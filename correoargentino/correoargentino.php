<?php
/**
 * Correo Argentino — MiCorreo carrier module for PrestaShop 9
 *
 * Integrates the PAQ.AR API to show live shipping rates at checkout
 * and import orders into MiCorreo after purchase.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/MiCorreoClient.php';

class CorreoArgentino extends CarrierModule
{
    // Configuration key prefix
    const CFG_PREFIX = 'CORREOARG_';

    // Individual config keys
    const CFG_USER_TOKEN      = 'CORREOARG_USER_TOKEN';
    const CFG_PASSWORD_TOKEN  = 'CORREOARG_PASSWORD_TOKEN';
    const CFG_EMAIL           = 'CORREOARG_EMAIL';
    const CFG_PASSWORD        = 'CORREOARG_PASSWORD';
    const CFG_CUSTOMER_ID     = 'CORREOARG_CUSTOMER_ID';
    const CFG_JWT             = 'CORREOARG_JWT';
    const CFG_JWT_EXPIRES     = 'CORREOARG_JWT_EXPIRES';
    const CFG_ORIGIN_POSTAL   = 'CORREOARG_ORIGIN_POSTAL';
    const CFG_ENVIRONMENT     = 'CORREOARG_ENVIRONMENT';
    const CFG_OFFER_DOMICILIO = 'CORREOARG_OFFER_DOMICILIO';
    const CFG_OFFER_SUCURSAL  = 'CORREOARG_OFFER_SUCURSAL';
    const CFG_CARRIER_ID_D    = 'CORREOARG_CARRIER_ID_D';
    const CFG_CARRIER_ID_S    = 'CORREOARG_CARRIER_ID_S';

    /**
     * Per-request rates cache: keyed by "<cartId>_<postalDest>" → full rates response.
     * Avoids making two API calls when PS evaluates both carriers in the same page load.
     *
     * @var array
     */
    private static $ratesCache = [];

    /** @var int Carrier ID set by PS before calling getOrderShippingCost */
    public $id_carrier;

    public function __construct()
    {
        $this->name    = 'correoargentino';
        $this->tab     = 'shipping_logistics';
        $this->version = '1.1.0';
        $this->author  = 'ParqueCD';
        $this->need_instance = 1;
        $this->ps_versions_compliancy = [
            'min' => '9.0.0',
            'max' => '9.99.99',
        ];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans(
            'Correo Argentino — PAQ.AR',
            [],
            'Modules.Correoargentino.Admin'
        );
        $this->description = $this->trans(
            'Muestra tarifas de Correo Argentino en el checkout e importa los envíos a MiCorreo.',
            [],
            'Modules.Correoargentino.Admin'
        );
        $this->confirmUninstall = $this->trans(
            '¿Estás seguro de que querés desinstalar el módulo de Correo Argentino?',
            [],
            'Modules.Correoargentino.Admin'
        );

        if (!Configuration::get(self::CFG_USER_TOKEN)) {
            $this->warning = $this->trans(
                'Configurá las credenciales de la API de MiCorreo.',
                [],
                'Modules.Correoargentino.Admin'
            );
        }
    }

    // -------------------------------------------------------------------------
    // Install / Uninstall
    // -------------------------------------------------------------------------

    public function install()
    {
        if (Shop::isFeatureActive()) {
            Shop::setContext(Shop::CONTEXT_ALL);
        }

        if (!parent::install()) {
            return false;
        }

        // Register hooks
        $hooks = [
            'actionCarrierUpdate',
            'actionValidateOrder',
        ];
        foreach ($hooks as $hook) {
            if (!$this->registerHook($hook)) {
                return false;
            }
        }

        // Set default configuration
        $defaults = [
            self::CFG_ENVIRONMENT     => 'TEST',
            self::CFG_OFFER_DOMICILIO => '1',
            self::CFG_OFFER_SUCURSAL  => '1',
            self::CFG_ORIGIN_POSTAL   => '1406',
        ];
        foreach ($defaults as $key => $value) {
            Configuration::updateValue($key, $value);
        }

        // Create the two carrier entries
        if (!$this->_createCarriers()) {
            return false;
        }

        return true;
    }

    public function uninstall()
    {
        // Delete carriers created by this module
        $this->_deleteCarriers();

        // Delete all configuration keys
        $keys = [
            self::CFG_USER_TOKEN,
            self::CFG_PASSWORD_TOKEN,
            self::CFG_EMAIL,
            self::CFG_PASSWORD,
            self::CFG_CUSTOMER_ID,
            self::CFG_JWT,
            self::CFG_JWT_EXPIRES,
            self::CFG_ORIGIN_POSTAL,
            self::CFG_ENVIRONMENT,
            self::CFG_OFFER_DOMICILIO,
            self::CFG_OFFER_SUCURSAL,
            self::CFG_CARRIER_ID_D,
            self::CFG_CARRIER_ID_S,
        ];
        foreach ($keys as $key) {
            Configuration::deleteByName($key);
        }

        return parent::uninstall();
    }

    // -------------------------------------------------------------------------
    // Carrier Management
    // -------------------------------------------------------------------------

    private function _createCarriers()
    {
        $carriers = [
            'D' => [
                'name'  => 'Correo Argentino — Domicilio',
                'delay' => 'Entrega a domicilio (PAQ.AR Clásico)',
            ],
            'S' => [
                'name'  => 'Correo Argentino — Sucursal',
                'delay' => 'Retiro en sucursal (PAQ.AR Clásico)',
            ],
        ];

        foreach ($carriers as $type => $data) {
            $carrier = new Carrier();
            $carrier->name                 = $data['name'];
            $carrier->active               = true;
            $carrier->deleted              = false;
            $carrier->shipping_handling    = false;
            $carrier->range_behavior       = 0;
            $carrier->is_module            = true;
            $carrier->is_free              = false;
            $carrier->shipping_external    = true;
            $carrier->need_range           = true;
            $carrier->external_module_name = $this->name;
            $carrier->shipping_method      = 2; // by price

            // Delay string must be set for all active languages
            foreach (Language::getLanguages() as $lang) {
                $carrier->delay[$lang['id_lang']] = $data['delay'];
            }

            if (!$carrier->add()) {
                return false;
            }

            // Associate carrier to all zones
            foreach (Zone::getZones(true) as $zone) {
                $carrier->addZone($zone['id_zone']);
            }

            // Associate carrier to all groups
            foreach (Group::getGroups(true) as $group) {
                $carrier->addGroup($group['id_group']);
            }

            // Add a catch-all price range so the carrier is selectable at checkout.
            // The actual price is returned dynamically by getOrderShippingCost().
            $range = new RangePrice();
            $range->id_carrier = $carrier->id;
            $range->delimiter1 = '0';
            $range->delimiter2 = '10000';
            $range->add();

            // Add a shipping fee of 0 for that range (actual price comes from getOrderShippingCost)
            Db::getInstance()->insert('delivery', [
                'id_carrier'      => (int) $carrier->id,
                'id_range_price'  => (int) $range->id,
                'id_range_weight' => 0,
                'id_zone'         => 0,
                'price'           => '0',
            ]);

            // Persist carrier ID so we can identify D vs S in hooks
            $cfgKey = ($type === 'D') ? self::CFG_CARRIER_ID_D : self::CFG_CARRIER_ID_S;
            Configuration::updateValue($cfgKey, $carrier->id);

            // Copy module logo to carrier image directory
            $logoSrc = __DIR__ . '/logo.png';
            $logoDst = _PS_SHIP_IMG_DIR_ . (int) $carrier->id . '.jpg';
            if (file_exists($logoSrc)) {
                copy($logoSrc, $logoDst);
            }
        }

        return true;
    }

    private function _deleteCarriers()
    {
        foreach ([self::CFG_CARRIER_ID_D, self::CFG_CARRIER_ID_S] as $cfgKey) {
            $carrierId = (int) Configuration::get($cfgKey);
            if ($carrierId) {
                $carrier = new Carrier($carrierId);
                if (Validate::isLoadedObject($carrier)) {
                    $carrier->delete();
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // Hook: actionCarrierUpdate
    // -------------------------------------------------------------------------

    public function hookActionCarrierUpdate($params)
    {
        $oldId = (int) $params['id_carrier'];
        $newId = (int) $params['carrier']->id;

        foreach ([self::CFG_CARRIER_ID_D, self::CFG_CARRIER_ID_S] as $cfgKey) {
            if ((int) Configuration::get($cfgKey) === $oldId) {
                Configuration::updateValue($cfgKey, $newId);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Carrier Rate Hooks
    // -------------------------------------------------------------------------

    /**
     * Returns the shipping cost for this carrier.
     *
     * OPTIMIZATION: Both carriers (D and S) are evaluated by PS in the same page
     * load. Rather than making two separate API calls, we request both rates in a
     * single POST /rates (omitting deliveredType) and cache the result for the
     * duration of the request. The second carrier evaluation hits the cache.
     *
     * @param array $params
     * @param float $shipping_cost  Ignored — we return the live API price
     * @return float|false
     */
    public function getOrderShippingCost($params, $shipping_cost)
    {
        /** @var Cart $cart */
        $cart = $params['cart'];

        $deliveryType = $this->_getDeliveryTypeForCurrentCarrier();
        if ($deliveryType === false) {
            return false;
        }

        if ($deliveryType === 'D' && !Configuration::get(self::CFG_OFFER_DOMICILIO)) {
            return false;
        }
        if ($deliveryType === 'S' && !Configuration::get(self::CFG_OFFER_SUCURSAL)) {
            return false;
        }

        $address = new Address($cart->id_address_delivery);
        if (!Validate::isLoadedObject($address)) {
            return false;
        }
        $postalDest = trim($address->postcode);
        if (empty($postalDest)) {
            return false;
        }

        $dimensions = $this->_computeCartDimensions($cart);
        if ($dimensions === false) {
            return false;
        }

        // Try cache first
        $cacheKey = $cart->id . '_' . $postalDest;
        if (!isset(self::$ratesCache[$cacheKey])) {
            self::$ratesCache[$cacheKey] = $this->_fetchBothRates($cart, $postalDest, $dimensions);
        }

        $ratesResponse = self::$ratesCache[$cacheKey];
        if ($ratesResponse === false || empty($ratesResponse['rates'])) {
            return false;
        }

        foreach ($ratesResponse['rates'] as $rate) {
            if ($rate['deliveredType'] === $deliveryType) {
                return (float) $rate['price'];
            }
        }

        return false;
    }

    /** Required by CarrierModule for external carriers. */
    public function getOrderShippingCostExt($params)
    {
        return $this->getOrderShippingCost($params, 0);
    }

    /**
     * Make one POST /rates call without deliveredType to get both D and S prices.
     * Returns the full response array, or false on error.
     *
     * @param Cart   $cart
     * @param string $postalDest
     * @param array  $dimensions
     * @return array|false
     */
    private function _fetchBothRates(Cart $cart, $postalDest, array $dimensions)
    {
        try {
            $env    = Configuration::get(self::CFG_ENVIRONMENT) ?: 'TEST';
            $client = new MiCorreoClient($env);

            return $client->getRates([
                'customerId'            => Configuration::get(self::CFG_CUSTOMER_ID),
                'postalCodeOrigin'      => Configuration::get(self::CFG_ORIGIN_POSTAL),
                'postalCodeDestination' => $postalDest,
                // No deliveredType → MiCorreo returns both D and S in one response
                'dimensions'            => $dimensions,
            ]);
        } catch (Exception $e) {
            $this->_logRateError($e->getMessage(), $cart->id, $postalDest);

            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Hook: actionValidateOrder
    // -------------------------------------------------------------------------

    public function hookActionValidateOrder($params)
    {
        /** @var Order $order */
        $order = $params['order'];

        $carrierId  = (int) $order->id_carrier;
        $carrierIdD = (int) Configuration::get(self::CFG_CARRIER_ID_D);
        $carrierIdS = (int) Configuration::get(self::CFG_CARRIER_ID_S);

        // Only handle orders using one of our carriers
        if ($carrierId !== $carrierIdD && $carrierId !== $carrierIdS) {
            return;
        }

        $deliveryType = ($carrierId === $carrierIdD) ? 'D' : 'S';

        // Get delivery address and customer for recipient data
        $address      = new Address($order->id_address_delivery);
        $customer     = new Customer($order->id_customer);

        // Build cart dimensions from order products
        $cart         = new Cart($order->id_cart);

        $dimensions = $this->_computeCartDimensions($cart);
        if ($dimensions === false) {
            PrestaShopLogger::addLog(
                '[CorreoArgentino] No se pudieron calcular las dimensiones para la orden ' . $order->reference
                . '. Verificá que los productos tengan peso y dimensiones configurados.',
                2, null, 'Order', (int) $order->id
            );

            return;
        }

        $payload = [
            'customerId'  => Configuration::get(self::CFG_CUSTOMER_ID),
            'extOrderId'  => $order->reference,
            'orderNumber' => (string) $order->id,
            'recipient'   => [
                'name'      => $address->firstname . ' ' . $address->lastname,
                'email'     => $customer->email,
                'phone'     => $address->phone ?: '',
                'cellPhone' => $address->phone_mobile ?: '',
            ],
            'shipping'    => [
                'deliveryType' => $deliveryType,
                'weight'       => $dimensions['weight'],
                'declaredValue' => (float) $order->total_products_wt,
                'height'       => $dimensions['height'],
                'length'       => $dimensions['length'],
                'width'        => $dimensions['width'],
            ],
        ];

        // For home delivery, include the full destination address.
        // Sucursal (S) only needs the agency code, which MiCorreo derives from the carrier.
        if ($deliveryType === 'D') {
            $state = new State($address->id_state);
            $payload['shipping']['address'] = [
                'streetName'   => $address->address1,
                'streetNumber' => $address->address2 ?: 's/n',
                'floor'        => '',
                'apartment'    => '',
                'city'         => $address->city,
                'provinceCode' => $this->_getProvinceCode($state->iso_code),
                'postalCode'   => $address->postcode,
            ];
        }

        try {
            $env    = Configuration::get(self::CFG_ENVIRONMENT) ?: 'TEST';
            $client = new MiCorreoClient($env);
            $result = $client->importShipping($payload);

            PrestaShopLogger::addLog(
                '[CorreoArgentino] Envío importado OK — Orden: ' . $order->reference
                . ' | Tipo: ' . $deliveryType
                . ' | createdAt: ' . ($result['createdAt'] ?? 'n/a'),
                1, null, 'Order', (int) $order->id
            );
        } catch (Exception $e) {
            $classified = $this->_classifyImportError($e->getMessage());
            PrestaShopLogger::addLog(
                '[CorreoArgentino] Error al importar envío — Orden: ' . $order->reference
                . ' | ' . $classified,
                3, null, 'Order', (int) $order->id
            );
        }
    }

    // -------------------------------------------------------------------------
    // Back Office Config Page
    // -------------------------------------------------------------------------

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submit_correoargentino')) {
            $output .= $this->_processConfigForm();
        }

        return $output . $this->_renderConfigForm();
    }

    private function _processConfigForm()
    {
        $fields = [
            self::CFG_USER_TOKEN      => Tools::getValue('CORREOARG_USER_TOKEN'),
            self::CFG_PASSWORD_TOKEN  => Tools::getValue('CORREOARG_PASSWORD_TOKEN'),
            self::CFG_EMAIL           => Tools::getValue('CORREOARG_EMAIL'),
            self::CFG_PASSWORD        => Tools::getValue('CORREOARG_PASSWORD'),
            self::CFG_ORIGIN_POSTAL   => Tools::getValue('CORREOARG_ORIGIN_POSTAL'),
            self::CFG_ENVIRONMENT     => Tools::getValue('CORREOARG_ENVIRONMENT'),
            self::CFG_OFFER_DOMICILIO => (int) Tools::getValue('CORREOARG_OFFER_DOMICILIO'),
            self::CFG_OFFER_SUCURSAL  => (int) Tools::getValue('CORREOARG_OFFER_SUCURSAL'),
        ];

        // Basic validation — required fields must not be empty
        foreach ([self::CFG_USER_TOKEN, self::CFG_PASSWORD_TOKEN, self::CFG_EMAIL, self::CFG_ORIGIN_POSTAL] as $required) {
            if (empty($fields[$required])) {
                return $this->displayError(
                    $this->trans('Todos los campos marcados con * son obligatorios.', [], 'Modules.Correoargentino.Admin')
                );
            }
        }

        foreach ($fields as $key => $value) {
            Configuration::updateValue($key, $value);
        }

        try {
            $env    = $fields[self::CFG_ENVIRONMENT] ?: 'TEST';
            $client = new MiCorreoClient($env);

            // Force fresh token
            Configuration::updateValue(self::CFG_JWT, '');
            Configuration::updateValue(self::CFG_JWT_EXPIRES, '');

            $client->getToken(
                $fields[self::CFG_USER_TOKEN],
                $fields[self::CFG_PASSWORD_TOKEN]
            );

            // Validate MiCorreo account credentials and fetch customerId
            $validated = $client->validateUser(
                $fields[self::CFG_EMAIL],
                $fields[self::CFG_PASSWORD]
            );

            if (!empty($validated['customerId'])) {
                Configuration::updateValue(self::CFG_CUSTOMER_ID, $validated['customerId']);

                return $this->displayConfirmation(
                    $this->trans('Configuración guardada. Customer ID: ', [], 'Modules.Correoargentino.Admin')
                    . '<strong>' . htmlspecialchars($validated['customerId']) . '</strong>'
                );
            }

            return $this->displayWarning(
                $this->trans('Credenciales guardadas pero no se pudo obtener el Customer ID.', [], 'Modules.Correoargentino.Admin')
            );
        } catch (Exception $e) {
            // Credentials were saved to DB; warn about the API validation failure
            // so the admin knows they may need to fix them before rates will work.
            return $this->displayWarning(
                $this->trans('Configuración guardada. Error al validar credenciales: ', [], 'Modules.Correoargentino.Admin')
                . htmlspecialchars($e->getMessage())
            );
        }
    }

    private function _renderConfigForm()
    {
        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Credenciales MiCorreo', [], 'Modules.Correoargentino.Admin'),
                    'icon'  => 'icon-truck',
                ],
                'input' => [
                    [
                        'type'    => 'select',
                        'label'   => $this->trans('Ambiente', [], 'Modules.Correoargentino.Admin'),
                        'name'    => 'CORREOARG_ENVIRONMENT',
                        'options' => [
                            'query' => [
                                ['id' => 'TEST', 'name' => 'TEST — apitest.correoargentino.com.ar'],
                                ['id' => 'PROD', 'name' => 'PROD — api.correoargentino.com.ar'],
                            ],
                            'id'   => 'id',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type'  => 'text',
                        'label' => $this->trans('User Token (Basic auth usuario) *', [], 'Modules.Correoargentino.Admin'),
                        'name'  => 'CORREOARG_USER_TOKEN',
                        'size'  => 60,
                    ],
                    [
                        'type'  => 'password',
                        'label' => $this->trans('Password Token (Basic auth contraseña) *', [], 'Modules.Correoargentino.Admin'),
                        'name'  => 'CORREOARG_PASSWORD_TOKEN',
                        'size'  => 60,
                    ],
                    [
                        'type'  => 'text',
                        'label' => $this->trans('Email de cuenta MiCorreo *', [], 'Modules.Correoargentino.Admin'),
                        'name'  => 'CORREOARG_EMAIL',
                        'size'  => 60,
                    ],
                    [
                        'type'  => 'password',
                        'label' => $this->trans('Contraseña de cuenta MiCorreo', [], 'Modules.Correoargentino.Admin'),
                        'name'  => 'CORREOARG_PASSWORD',
                        'size'  => 60,
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Código Postal de origen (tienda) *', [], 'Modules.Correoargentino.Admin'),
                        'name'  => 'CORREOARG_ORIGIN_POSTAL',
                        'size'  => 10,
                        'desc'  => $this->trans('CP desde donde salen los paquetes. Ej: 1406 (Parque Rivadavia)', [], 'Modules.Correoargentino.Admin'),
                    ],
                    [
                        'type'   => 'switch',
                        'label'  => $this->trans('Ofrecer envío a domicilio', [], 'Modules.Correoargentino.Admin'),
                        'name'   => 'CORREOARG_OFFER_DOMICILIO',
                        'values' => [
                            ['id' => 'dom_on',  'value' => 1, 'label' => $this->trans('Sí', [], 'Modules.Correoargentino.Admin')],
                            ['id' => 'dom_off', 'value' => 0, 'label' => $this->trans('No', [], 'Modules.Correoargentino.Admin')],
                        ],
                    ],
                    [
                        'type'   => 'switch',
                        'label'  => $this->trans('Ofrecer retiro en sucursal', [], 'Modules.Correoargentino.Admin'),
                        'name'   => 'CORREOARG_OFFER_SUCURSAL',
                        'values' => [
                            ['id' => 'suc_on',  'value' => 1, 'label' => $this->trans('Sí', [], 'Modules.Correoargentino.Admin')],
                            ['id' => 'suc_off', 'value' => 0, 'label' => $this->trans('No', [], 'Modules.Correoargentino.Admin')],
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Guardar y validar', [], 'Modules.Correoargentino.Admin'),
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar  = false;
        $helper->table         = $this->table;
        $helper->module        = $this;
        $helper->default_form_language    = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
        $helper->identifier    = $this->identifier;
        $helper->submit_action = 'submit_correoargentino';
        $helper->currentIndex  = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name
            . '&tab_module=' . $this->tab
            . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        // Populate current saved values into the form fields
        $helper->fields_value = [
            'CORREOARG_ENVIRONMENT'     => Configuration::get(self::CFG_ENVIRONMENT) ?: 'TEST',
            'CORREOARG_USER_TOKEN'      => Configuration::get(self::CFG_USER_TOKEN),
            'CORREOARG_PASSWORD_TOKEN'  => Configuration::get(self::CFG_PASSWORD_TOKEN),
            'CORREOARG_EMAIL'           => Configuration::get(self::CFG_EMAIL),
            'CORREOARG_PASSWORD'        => Configuration::get(self::CFG_PASSWORD),
            'CORREOARG_ORIGIN_POSTAL'   => Configuration::get(self::CFG_ORIGIN_POSTAL) ?: '1406',
            'CORREOARG_OFFER_DOMICILIO' => Configuration::get(self::CFG_OFFER_DOMICILIO),
            'CORREOARG_OFFER_SUCURSAL'  => Configuration::get(self::CFG_OFFER_SUCURSAL),
        ];

        // Show current credential status banner above the form
        $customerId = Configuration::get(self::CFG_CUSTOMER_ID);
        $statusHtml = '';
        if ($customerId) {
            $statusHtml = '<div class="alert alert-success">'
                . $this->trans('Customer ID activo: ', [], 'Modules.Correoargentino.Admin')
                . '<strong>' . htmlspecialchars($customerId) . '</strong>'
                . ' &mdash; Ambiente: <strong>' . htmlspecialchars(Configuration::get(self::CFG_ENVIRONMENT)) . '</strong>'
                . '</div>';
        }

        return $statusHtml . $helper->generateForm([$fields_form]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Map current $this->id_carrier to delivery type 'D' or 'S'.
     *
     * @return string|false
     */
    private function _getDeliveryTypeForCurrentCarrier()
    {
        $id = (int) $this->id_carrier;
        if ($id === (int) Configuration::get(self::CFG_CARRIER_ID_D)) {
            return 'D';
        }
        if ($id === (int) Configuration::get(self::CFG_CARRIER_ID_S)) {
            return 'S';
        }

        return false;
    }

    /**
     * Compute virtual container dimensions from cart products.
     *
     * Formula (matches ylazzari-correoargentino npm package):
     *   weight = sum(weight_kg × 1000 × qty)          → grams
     *   height = sum(height_cm × qty)                  → cm, items stacked
     *   width  = max(width_cm)                         → cm, not multiplied by qty
     *   length = max(depth_cm × qty)                   → cm, longest row
     *
     * All values clamped to MiCorreo limits: weight 1–25000 g, dimensions 1–150 cm.
     *
     * @param Cart $cart
     * @return array|false  ['weight', 'height', 'width', 'length'] or false if cart is empty
     */
    private function _computeCartDimensions(Cart $cart)
    {
        $products = $cart->getProducts();
        if (empty($products)) {
            return false;
        }

        $totalWeight = 0; // grams
        $totalHeight = 0; // cm (stacked vertically)
        $maxWidth    = 0; // cm (widest item, not multiplied by qty)
        $maxLength   = 0; // cm (longest row: depth × qty per product)

        foreach ($products as $product) {
            $qty = max(1, (int) $product['cart_quantity']);

            // Weight: PrestaShop stores in kg → convert to grams
            $totalWeight += (int) round((float) $product['weight'] * 1000) * $qty;

            // height: stacked (sum × qty)
            $totalHeight += (int) ceil((float) $product['height']) * $qty;

            // width: widest item (not multiplied by qty)
            $maxWidth = max($maxWidth, (int) ceil((float) $product['width']));

            // length: longest row (depth × qty per product, then take the max across products)
            $maxLength = max($maxLength, (int) ceil((float) $product['depth']) * $qty);
        }

        // Clamp to MiCorreo API limits
        $totalWeight = max(1,   min(25000, $totalWeight));
        $totalHeight = max(1,   min(150,   $totalHeight));
        $maxWidth    = max(1,   min(150,   $maxWidth));
        $maxLength   = max(1,   min(150,   $maxLength));

        return [
            'weight' => $totalWeight,
            'height' => $totalHeight,
            'width'  => $maxWidth,
            'length' => $maxLength,
        ];
    }

    /**
     * Map PrestaShop state ISO code to MiCorreo single-letter province code.
     * PS uses 'AR-B', 'AR-C', etc. MiCorreo uses 'B', 'C', etc.
     *
     * @param string $isoCode
     * @return string
     */
    private function _getProvinceCode($isoCode)
    {
        // PS state ISO for Argentina is typically 'AR-B', 'AR-C', etc.
        // MiCorreo expects the single letter after the dash.
        if (strpos($isoCode, '-') !== false) {
            $parts = explode('-', $isoCode);

            return strtoupper(end($parts));
        }

        return strtoupper($isoCode);
    }

    /**
     * Log a rate-fetch error with context about likely causes.
     *
     * @param string $message
     * @param int    $cartId
     * @param string $postalDest
     */
    private function _logRateError($message, $cartId, $postalDest)
    {
        $classified = $this->_classifyRateError($message);
        PrestaShopLogger::addLog(
            '[CorreoArgentino] Error al cotizar — Cart #' . $cartId
            . ' | Destino CP: ' . $postalDest
            . ' | ' . $classified,
            2, null, 'Cart', $cartId
        );
    }

    /**
     * Classify a MiCorreo /rates API error into an actionable message.
     * Error strings sourced from ylazzari-correoargentino documentation.
     *
     * @param string $rawMessage
     * @return string
     */
    private function _classifyRateError($rawMessage)
    {
        $map = [
            'Cliente FAP no identificado' =>
                'Customer ID inválido para este ambiente. Verificá CORREOARG_CUSTOMER_ID y el ambiente (TEST/PROD).',
            'Unauthorized' =>
                'Token inválido o expirado. Verificá User Token / Password Token en la configuración del módulo.',
            'Customer ID no valido' =>
                'Customer ID no existe en este ambiente. Guardá la configuración nuevamente para regenerarlo.',
        ];

        return $this->_matchErrorMessage($rawMessage, $map);
    }

    /**
     * Classify a MiCorreo /shipping/import API error into an actionable message.
     * Error strings sourced from MiCorreo API docs + ylazzari-correoargentino.
     *
     * @param string $rawMessage
     * @return string
     */
    private function _classifyImportError($rawMessage)
    {
        $map = [
            'La orden ya fue importada con anterioridad' =>
                'Orden duplicada — extOrderId ya existe en MiCorreo. La orden ya estaba importada.',
            'Peso no valido' =>
                'Peso inválido. Verificá que los productos tengan peso configurado (Catálogo → Productos → Envío).',
            'El peso debe ser mayor a 0' =>
                'Peso = 0. Configurá el peso de los productos en PrestaShop.',
            'El peso excede el maximo permitido' =>
                'Peso supera 25.000 g (25 kg), límite de PAQ.AR.',
            'Tipo de entrega invalido' =>
                'deliveryType inválido. Debe ser "D" o "S".',
            'Verifique la sucursal de destino' =>
                'Código de sucursal incorrecto o inactiva.',
            'no se encontro datos de remitente' =>
                'El perfil de MiCorreo no tiene datos de remitente. Completá el perfil en micorreo.correoargentino.com.ar o pasá sender explícito.',
            'El codigo Postal del emisor debe tener valor' =>
                'El perfil de MiCorreo no tiene CP de origen. Completá el perfil o verificá CORREOARG_ORIGIN_POSTAL.',
            'La provincia del emisor debe tener valor' =>
                'El perfil de MiCorreo no tiene provincia de origen.',
            'La provincia es invalida' =>
                'Código de provincia inválido. Verificá el mapeo de provincias en _getProvinceCode().',
            'El alto debe estar entre' =>
                'Alto fuera de rango (máx. 255 cm según API). Revisá dimensiones del producto.',
            'El ancho debe estar entre' =>
                'Ancho fuera de rango (máx. 255 cm según API). Revisá dimensiones del producto.',
            'El largo debe estar entre' =>
                'Largo fuera de rango (máx. 255 cm según API). Revisá dimensiones del producto.',
            'Unauthorized' =>
                'Token inválido o expirado. Volvé a guardar la configuración del módulo.',
        ];

        return $this->_matchErrorMessage($rawMessage, $map);
    }

    /**
     * Match a raw API error message against a known-errors map.
     * Returns the actionable message if matched, or the raw message otherwise.
     *
     * @param string $rawMessage
     * @param array  $map  substring → actionable description
     * @return string
     */
    private function _matchErrorMessage($rawMessage, array $map)
    {
        foreach ($map as $needle => $description) {
            if (stripos($rawMessage, $needle) !== false) {
                return $description . ' [raw: ' . $rawMessage . ']';
            }
        }

        return 'Error desconocido: ' . $rawMessage;
    }
}
