# Prompt - Expedientes MWT

Este documento describe los tres contextos principales que necesitas conocer para trabajar con el sistema de expedientes de MWT: el backend (API de tracking), el frontend (sitio web) y la base de datos.

---

## 1. Backend — API de Tracking

### Autenticación

Todas las peticiones POST a los endpoints deben incluir **siempre** las siguientes claves de autenticación en el body:

```json
{
  "keyhash": "e474389ae582ee303d6a7d0c6307e6beb373015edfa7b67f1a02bab62367d66f",
  "keyuser": "e474389ae582ee303d6a7d0c6307e6beb373015edfa7b67f1a02bab62367d66f"
}
```

> ⚠️ Es un **ambiente de pruebas**. Se pueden hacer peticiones POST sin restricciones adicionales.

---

### Endpoint 1 — Listado de Expedientes

**URL:**
```
POST https://muitowork.com/api-tracking/order.php
```

**Descripción:**
Retorna el listado de todos los expedientes disponibles con su `id` y `estado`.

**Body mínimo requerido:**
```json
{
  "keyhash": "e474389ae582ee303d6a7d0c6307e6beb373015edfa7b67f1a02bab62367d66f",
  "keyuser": "e474389ae582ee303d6a7d0c6307e6beb373015edfa7b67f1a02bab62367d66f"
}
```

---

### Endpoint 2 — Detalle por Estado del Expediente

**URL:**
```
POST https://muitowork.com/api-tracking/api.php
```

**Descripción:**
Retorna la información detallada de un expediente según su estado actual. El campo `action` determina qué tipo de información se consulta.

**Body completo:**
```json
{
  "keyhash": "e474389ae582ee303d6a7d0c6307e6beb373015edfa7b67f1a02bab62367d66f",
  "keyuser": "e474389ae582ee303d6a7d0c6307e6beb373015edfa7b67f1a02bab62367d66f",
  "order_number": "MWT-0028-2025",
  "action": "<ver tabla de acciones>"
}
```

### Tabla de Acciones por Estado

| Estado del Expediente | Valor del campo `action` |
|-----------------------|--------------------------|
| Creación              | `getPreforma`            |
| Crédito               | `getCreditOrderData`     |
| Producción            | `listProduccionInfo`     |
| Preparación           | `listPreparacionInfo`    |
| Despacho              | `listDespachoInfo`       |
| Tránsito              | `listTransitoInfo`       |
| Pago                  | `listPagoInfo`           |

**Ejemplo de petición para estado "Producción":**
```json
{
  "keyhash": "e474389ae582ee303d6a7d0c6307e6beb373015edfa7b67f1a02bab62367d66f",
  "keyuser": "e474389ae582ee303d6a7d0c6307e6beb373015edfa7b67f1a02bab62367d66f",
  "order_number": "MWT-0028-2025",
  "action": "listProduccionInfo"
}
```

---

## 2. Frontend — Sitio Web MWT

### Información General

| Ítem            | Valor                                   |
|-----------------|-----------------------------------------|
| Sitio principal | https://mwt.one/es/                     |
| Login           | https://mwt.one/es/log                  |
| Usuario         | `ale132402`                             |
| Contraseña      | `Ale3046405009?`                        |
| Historial       | https://mwt.one/es/area/history         |

> ⚠️ Es un **sitio de pruebas**. Se puede navegar e iniciar sesión sin ningún problema.

---

### Navegación Principal

#### 1. Iniciar Sesión
Acceder a:
```
https://mwt.one/es/log
```
Usar las credenciales indicadas arriba.

#### 2. Ver Tablero de Expedientes
Una vez autenticado, ir a:
```
https://mwt.one/es/area/history
```
Aquí se muestra el tablero con **todos los expedientes** del usuario.

#### 3. Ver Detalle de un Expediente
La URL sigue el siguiente patrón:
```
https://mwt.one/es/?option=com_sppagebuilder&view=page&id=143&order_number=MWT-XXXX-XXXX
```

**Ejemplo con expediente `MWT-0007-2026`:**
```
https://mwt.one/es/?option=com_sppagebuilder&view=page&id=143&order_number=MWT-0007-2026
```

**Parámetros de la URL:**

| Parámetro      | Descripción                                              |
|----------------|----------------------------------------------------------|
| `option`       | Siempre `com_sppagebuilder`                              |
| `view`         | Siempre `page`                                           |
| `id`           | ID de la página Joomla (actualmente `143`)               |
| `order_number` | Número del expediente, ej: `MWT-0007-2026`               |

---

## 3. Base de Datos

### Repositorio de la API Backend

El repositorio con el código de la API y la estructura de base de datos se encuentra en:

```
https://github.com/Ale241302/api_expedientes_mwt_one
```

### Archivo SQL

La estructura completa de las tablas utilizadas para los expedientes está en:

```
/sql/embarc7_mwt0523.sql
```

**URL de descarga directa (raw):**
```
https://raw.githubusercontent.com/Ale241302/api_expedientes_mwt_one/main/sql/embarc7_mwt0523.sql
```

> Este archivo contiene el dump completo de la base de datos MySQL/MariaDB con todas las tablas relacionadas al sistema de expedientes, incluyendo: órdenes, estados, productos, clientes, créditos, producción, preparación, despacho, tránsito y pagos.

### Archivos PHP Relevantes del Backend

| Archivo              | Descripción                                          |
|----------------------|------------------------------------------------------|
| `api.php`            | Endpoint principal de detalle por estado             |
| `order.php`          | Listado de expedientes                               |
| `orderdetalle.php`   | Detalle extendido de una orden                       |
| `status.php`         | Gestión de estados                                   |
| `config.php`         | Configuración de conexión a base de datos            |
| `login.php`          | Autenticación de usuarios                            |
| `monitor.php`        | Monitoreo general                                    |
| `cart.php`           | Gestión del carrito de compras                       |
| `comprarproduct.php` | Proceso de compra de productos                       |
| `splitorder.php`     | División de órdenes                                  |
| `joinorder.php`      | Unión de órdenes                                     |
| `cron_email.php`     | Envío de emails automáticos (cron job)               |

### Estructura de Carpetas del Repositorio

```
api_expedientes_mwt_one/
├── sql/
│   └── embarc7_mwt0523.sql   ← Estructura y datos de la BD
├── app/                       ← Lógica de aplicación
├── email/                     ← Plantillas de email
├── api.php                    ← Endpoint principal
├── order.php                  ← Listado de expedientes
├── config.php                 ← Configuración BD
└── ...otros archivos PHP
```
