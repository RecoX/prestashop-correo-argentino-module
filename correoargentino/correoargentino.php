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

    /** @var array Carrier IDs created by this module (populated after install) */
    public $id_carrier;

    public function __construct()
    {
        $this->name    = 'correoargentino';
        $this->tab     = 'shipping_logistics';
        $this->version = '1.0.0';
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

    /**
     * Create the two PrestaShop carrier entries (Domicilio and Sucursal).
     */
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
            $zones = Zone::getZones(true);
            foreach ($zones as $zone) {
                $carrier->addZone($zone['id_zone']);
            }

            // Associate carrier to all groups
            $groups = Group::getGroups(true);
            foreach ($groups as $group) {
                $carrier->addGroup($group['id_group']);
            }

            // Add a catch-all price range so the carrier is selectable
            $range = new RangePrice();
            $range->id_carrier = $carrier->id;
            $range->delimiter1 = '0';
            $range->delimiter2 = '10000';
            $range->add();

            // Add a shipping fee of 0 for that range (actual price comes from getOrderShippingCost)
            Db::getInstance()->insert('delivery', [
                'id_carrier'    => (int) $carrier->id,
                'id_range_price' => (int) $range->id,
                'id_range_weight' => 0,
                'id_zone'       => 0,
                'price'         => '0',
            ]);

            // Persist carrier ID
            $cfgKey = ($type === 'D') ? self::CFG_CARRIER_ID_D : self::CFG_CARRIER_ID_S;
            Configuration::updateValue($cfgKey, $carrier->id);

            // Copy module logo to carrier image
            $logoSrc = __DIR__ . '/logo.png';
            $logoDst = _PS_SHIP_IMG_DIR_ . (int) $carrier->id . '.jpg';
            if (file_exists($logoSrc)) {
                copy($logoSrc, $logoDst);
            }
        }

        return true;
    }

    /**
     * Delete carriers created by this module.
     */
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

    /**
     * When PrestaShop duplicates a carrier (on edit), update our stored IDs.
     */
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
    // Carrier Rate Hook
    // -------------------------------------------------------------------------

    /**
     * Returns the shipping cost for this carrier.
     * Called by PrestaShop for each carrier associated with this module.
     *
     * @param array $params  Contains 'cart', 'shipping_cost', 'address', etc.
     * @param float $shipping_cost  Default cost (we override it)
     * @return float|false  Price in store currency, or false to hide this carrier
     */
    public function getOrderShippingCost($params, $shipping_cost)
    {
        /** @var Cart $cart */
        $cart = $params['cart'];

        // Determine which delivery type this carrier handles
        $deliveryType = $this->_getDeliveryTypeForCurrentCarrier();
        if ($deliveryType === false) {
            return false;
        }

        // Check if this delivery type is enabled in settings
        if ($deliveryType === 'D' && !Configuration::get(self::CFG_OFFER_DOMICILIO)) {
            return false;
        }
        if ($deliveryType === 'S' && !Configuration::get(self::CFG_OFFER_SUCURSAL)) {
            return false;
        }

        // Get destination postal code
        $address = new Address($cart->id_address_delivery);
        if (!Validate::isLoadedObject($address)) {
            return false;
        }
        $postalDest = $address->postcode;
        if (empty($postalDest)) {
            return false;
        }

        // Compute virtual container dimensions from cart products
        $dimensions = $this->_computeCartDimensions($cart);
        if ($dimensions === false) {
            return false;
        }

        // Call MiCorreo API
        try {
            $env    = Configuration::get(self::CFG_ENVIRONMENT) ?: 'TEST';
            $client = new MiCorreoClient($env);

            $ratesResponse = $client->getRates([
                'customerId'            => Configuration::get(self::CFG_CUSTOMER_ID),
                'postalCodeOrigin'      => Configuration::get(self::CFG_ORIGIN_POSTAL),
                'postalCodeDestination' => $postalDest,
                'deliveredType'         => $deliveryType,
                'dimensions'            => $dimensions,
            ]);

            if (empty($ratesResponse['rates'])) {
                return false;
            }

            // Return first matching rate price
            foreach ($ratesResponse['rates'] as $rate) {
                if ($rate['deliveredType'] === $deliveryType) {
                    return (float) $rate['price'];
                }
            }

            return false;
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'CorreoArgentino::getOrderShippingCost error: ' . $e->getMessage(),
                3,
                null,
                'Cart',
                (int) $cart->id
            );

            return false;
        }
    }

    /**
     * Required by CarrierModule — same as getOrderShippingCost for external carriers.
     */
    public function getOrderShippingCostExt($params)
    {
        return $this->getOrderShippingCost($params, 0);
    }

    // -------------------------------------------------------------------------
    // Hook: actionValidateOrder
    // -------------------------------------------------------------------------

    /**
     * After an order is placed, import the shipment into MiCorreo.
     */
    public function hookActionValidateOrder($params)
    {
        /** @var Order $order */
        $order = $params['order'];

        // Only handle orders using one of our carriers
        $carrierId = (int) $order->id_carrier;
        $carrierIdD = (int) Configuration::get(self::CFG_CARRIER_ID_D);
        $carrierIdS = (int) Configuration::get(self::CFG_CARRIER_ID_S);

        if ($carrierId !== $carrierIdD && $carrierId !== $carrierIdS) {
            return;
        }

        $deliveryType = ($carrierId === $carrierIdD) ? 'D' : 'S';

        // Get delivery address
        $address  = new Address($order->id_address_delivery);
        $customer = new Customer($order->id_customer);

        // Build cart dimensions from order detail
        $cart = new Cart($order->id_cart);
        $dimensions = $this->_computeCartDimensions($cart);
        if ($dimensions === false) {
            PrestaShopLogger::addLog(
                'CorreoArgentino::hookActionValidateOrder — could not compute dimensions for order ' . $order->reference,
                2,
                null,
                'Order',
                (int) $order->id
            );

            return;
        }

        $payload = [
            'customerId'   => Configuration::get(self::CFG_CUSTOMER_ID),
            'extOrderId'   => $order->reference,
            'orderNumber'  => (string) $order->id,
            'recipient'    => [
                'name'      => $address->firstname . ' ' . $address->lastname,
                'email'     => $customer->email,
                'phone'     => $address->phone ?: '',
                'cellPhone' => $address->phone_mobile ?: '',
            ],
            'shipping'     => [
                'deliveryType' => $deliveryType,
                'weight'       => $dimensions['weight'],
                'declaredValue' => (float) $order->total_products_wt,
                'height'       => $dimensions['height'],
                'length'       => $dimensions['length'],
                'width'        => $dimensions['width'],
            ],
        ];

        // For home delivery, include the full address
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
                'CorreoArgentino: envío importado para orden ' . $order->reference . ' — ' . json_encode($result),
                1,
                null,
                'Order',
                (int) $order->id
            );
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'CorreoArgentino::importShipping error para orden ' . $order->reference . ': ' . $e->getMessage(),
                3,
                null,
                'Order',
                (int) $order->id
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

        // Basic validation
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

        // Try to get a token + customerId to validate credentials
        try {
            $env    = $fields[self::CFG_ENVIRONMENT] ?: 'TEST';
            $client = new MiCorreoClient($env);

            // Force fresh token
            Configuration::updateValue(self::CFG_JWT, '');
            Configuration::updateValue(self::CFG_JWT_EXPIRES, '');

            $token = $client->getToken(
                $fields[self::CFG_USER_TOKEN],
                $fields[self::CFG_PASSWORD_TOKEN]
            );

            // Validate user and get customerId
            $validated = $client->validateUser(
                $fields[self::CFG_EMAIL],
                $fields[self::CFG_PASSWORD]
            );

            if (!empty($validated['customerId'])) {
                Configuration::updateValue(self::CFG_CUSTOMER_ID, $validated['customerId']);

                return $this->displayConfirmation(
                    $this->trans(
                        'Configuración guardada. Customer ID: %s',
                        [$validated['customerId']],
                        'Modules.Correoargentino.Admin'
                    )
                );
            }

            return $this->displayWarning(
                $this->trans('Credenciales guardadas pero no se pudo obtener el Customer ID.', [], 'Modules.Correoargentino.Admin')
            );
        } catch (Exception $e) {
            // Credentials may still be saved; warn about API issue
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
                        'type'     => 'select',
                        'label'    => $this->trans('Ambiente', [], 'Modules.Correoargentino.Admin'),
                        'name'     => 'CORREOARG_ENVIRONMENT',
                        'required' => true,
                        'options'  => [
                            'query' => [
                                ['id' => 'TEST', 'name' => 'TEST — apitest.correoargentino.com.ar'],
                                ['id' => 'PROD', 'name' => 'PROD — api.correoargentino.com.ar'],
                            ],
                            'id'   => 'id',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type'     => 'text',
                        'label'    => $this->trans('User Token (Basic auth usuario) *', [], 'Modules.Correoargentino.Admin'),
                        'name'     => 'CORREOARG_USER_TOKEN',
                        'required' => true,
                        'size'     => 60,
                    ],
                    [
                        'type'     => 'password',
                        'label'    => $this->trans('Password Token (Basic auth contraseña) *', [], 'Modules.Correoargentino.Admin'),
                        'name'     => 'CORREOARG_PASSWORD_TOKEN',
                        'required' => true,
                        'size'     => 60,
                    ],
                    [
                        'type'     => 'text',
                        'label'    => $this->trans('Email de cuenta MiCorreo *', [], 'Modules.Correoargentino.Admin'),
                        'name'     => 'CORREOARG_EMAIL',
                        'required' => true,
                        'size'     => 60,
                    ],
                    [
                        'type'     => 'password',
                        'label'    => $this->trans('Contraseña de cuenta MiCorreo', [], 'Modules.Correoargentino.Admin'),
                        'name'     => 'CORREOARG_PASSWORD',
                        'size'     => 60,
                    ],
                    [
                        'type'     => 'text',
                        'label'    => $this->trans('Código Postal de origen (tienda) *', [], 'Modules.Correoargentino.Admin'),
                        'name'     => 'CORREOARG_ORIGIN_POSTAL',
                        'required' => true,
                        'size'     => 10,
                        'desc'     => $this->trans('Código postal desde donde se envían los paquetes. Ej: 1406', [], 'Modules.Correoargentino.Admin'),
                    ],
                    [
                        'type'  => 'switch',
                        'label' => $this->trans('Ofrecer envío a domicilio', [], 'Modules.Correoargentino.Admin'),
                        'name'  => 'CORREOARG_OFFER_DOMICILIO',
                        'values' => [
                            ['id' => 'dom_on', 'value' => 1, 'label' => $this->trans('Sí', [], 'Modules.Correoargentino.Admin')],
                            ['id' => 'dom_off', 'value' => 0, 'label' => $this->trans('No', [], 'Modules.Correoargentino.Admin')],
                        ],
                    ],
                    [
                        'type'  => 'switch',
                        'label' => $this->trans('Ofrecer retiro en sucursal', [], 'Modules.Correoargentino.Admin'),
                        'name'  => 'CORREOARG_OFFER_SUCURSAL',
                        'values' => [
                            ['id' => 'suc_on', 'value' => 1, 'label' => $this->trans('Sí', [], 'Modules.Correoargentino.Admin')],
                            ['id' => 'suc_off', 'value' => 0, 'label' => $this->trans('No', [], 'Modules.Correoargentino.Admin')],
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Guardar y validar', [], 'Modules.Correoargentino.Admin'),
                ],
            ],
        ];

        // Current values
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

        // Show current status
        $customerId = Configuration::get(self::CFG_CUSTOMER_ID);
        $statusHtml = '';
        if ($customerId) {
            $statusHtml = '<div class="alert alert-success">'
                . $this->trans('Customer ID activo: ', [], 'Modules.Correoargentino.Admin')
                . '<strong>' . htmlspecialchars($customerId) . '</strong>'
                . ' — Ambiente: <strong>' . htmlspecialchars(Configuration::get(self::CFG_ENVIRONMENT)) . '</strong>'
                . '</div>';
        }

        return $statusHtml . $helper->generateForm([$fields_form]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Determine which delivery type (D or S) the currently-being-evaluated
     * carrier corresponds to, by comparing $this->id_carrier with stored IDs.
     *
     * @return string|false 'D', 'S', or false
     */
    private function _getDeliveryTypeForCurrentCarrier()
    {
        $currentCarrierId = (int) $this->id_carrier;
        if ($currentCarrierId === (int) Configuration::get(self::CFG_CARRIER_ID_D)) {
            return 'D';
        }
        if ($currentCarrierId === (int) Configuration::get(self::CFG_CARRIER_ID_S)) {
            return 'S';
        }

        return false;
    }

    /**
     * Compute virtual container dimensions from cart products.
     * Returns array [weight, height, width, length] in grams/cm (integers).
     *
     * @param Cart $cart
     * @return array|false
     */
    private function _computeCartDimensions(Cart $cart)
    {
        $products = $cart->getProducts();
        if (empty($products)) {
            return false;
        }

        $totalWeight = 0; // grams
        $totalHeight = 0; // cm (stacked)
        $maxWidth    = 0; // cm
        $maxLength   = 0; // cm

        foreach ($products as $product) {
            $qty = (int) $product['cart_quantity'];

            // Weight: PS stores in kg, MiCorreo expects grams
            $weightKg = (float) $product['weight'];
            $totalWeight += (int) round($weightKg * 1000) * $qty;

            // Dimensions in cm
            $h = (int) ceil((float) $product['height']);
            $w = (int) ceil((float) $product['width']);
            $l = (int) ceil((float) $product['depth']); // PS uses 'depth' for length

            $totalHeight += $h * $qty;
            $maxWidth    = max($maxWidth, $w);
            $maxLength   = max($maxLength, $l * $qty);
        }

        // Apply MiCorreo limits
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
     * Map PrestaShop state ISO code to MiCorreo province code.
     * PS uses full ISO (e.g. AR-B), MiCorreo uses single letter (B).
     *
     * @param string $isoCode
     * @return string
     */
    private function _getProvinceCode($isoCode)
    {
        // PS state ISO for Argentina is typically 'AR-B', 'AR-C', etc.
        if (strpos($isoCode, '-') !== false) {
            $parts = explode('-', $isoCode);

            return strtoupper(end($parts));
        }

        return strtoupper($isoCode);
    }
}
