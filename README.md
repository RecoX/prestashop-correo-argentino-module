# correoargentino — PrestaShop 9 Carrier Module

Integrates Correo Argentino's **MiCorreo / PAQ.AR API** with PrestaShop 9.

## Features

- Live shipping rates (home delivery + branch pickup) at checkout
- Automatic shipment import to MiCorreo after order placement
- Back office configuration page with credential validation

## Requirements

- PrestaShop 9.0+
- PHP 7.4+
- Correo Argentino MiCorreo API credentials (request at https://www.correoargentino.com.ar/MiCorreo/public/contact)

## Installation

1. Upload the `correoargentino/` folder to your PrestaShop `modules/` directory
2. Go to Back Office → Modules → search "Correo Argentino" → Install
3. Configure credentials in the module settings page

## Configuration

| Field | Description |
|---|---|
| Ambiente | TEST or PROD |
| User Token | Basic auth username from Correo |
| Password Token | Basic auth password from Correo |
| Email | Your MiCorreo account email |
| Contraseña | Your MiCorreo account password |
| CP Origen | Sender postal code (your store location) |

## API Reference

https://www.correoargentino.com.ar/MiCorreo/public/img/pag/apiMiCorreo.pdf
