# Correo Argentino PAQ.AR — PrestaShop 9 Carrier Module

Integrates Correo Argentino's **MiCorreo / PAQ.AR API** with PrestaShop 9.  
Shows live shipping rates at checkout and automatically imports shipments into MiCorreo after purchase.

**Author:** Conreco Limited — lucas@conreco.com.ar  
**License:** Commercial  
**PrestaShop:** 9.0+  
**PHP:** 7.4+

---

## Features

- Live PAQ.AR rates (home delivery + branch pickup) shown at checkout
- Single API call for both delivery types (optimized — no double requests)
- Automatic shipment import to MiCorreo when order is placed
- Back office configuration page with credential validation
- Actionable error messages in PS logs (classified by error type)
- No Composer dependencies — pure PHP curl, works on shared hosting

## Requirements

- PrestaShop 9.0+
- PHP 7.4+
- Correo Argentino MiCorreo API credentials  
  → Request at https://www.correoargentino.com.ar/MiCorreo/public/contact

## Installation

1. Upload the `correoargentino/` folder to your PrestaShop `modules/` directory
2. Go to Back Office → Modules → search "Correo Argentino" → Install
3. Go to module configuration and enter your MiCorreo credentials

## Configuration

| Field | Description |
|---|---|
| Ambiente | `TEST` (sandbox) or `PROD` (live) |
| User Token | Basic auth username — provided by Correo |
| Password Token | Basic auth password — provided by Correo |
| Email | Your MiCorreo account email |
| Contraseña | Your MiCorreo account password |
| CP Origen | Sender postal code (your store location) |
| Envío a domicilio | Enable/disable home delivery carrier |
| Retiro en sucursal | Enable/disable branch pickup carrier |

On save the module will validate your credentials against the API and display your Customer ID if successful.

## How it works

### At checkout
PrestaShop evaluates both carriers (Domicilio + Sucursal) for the customer's cart. The module makes **one** API call to `POST /rates` (without `deliveredType`) to get both prices simultaneously, caches the result for the page load, and returns the matching price to each carrier.

### After order placement
`hookActionValidateOrder` fires. The module builds the shipment payload from the order and calls `POST /shipping/import`. Success and errors are logged to PrestaShop's log system (Advanced Parameters → Logs).

## Support

lucas@conreco.com.ar

## API Reference

https://www.correoargentino.com.ar/MiCorreo/public/img/pag/apiMiCorreo.pdf

## Changelog

### v1.1.0
- Single `/rates` API call with per-request cache (was two calls)
- Fixed dimension formula (`length = max(depth × qty)`, `width = max(width)`)
- Classified error logging — actionable messages for all known MiCorreo 402 errors
- Restored all inline code comments

### v1.0.0
- Initial release: carrier creation, rate hook, import hook, config page
