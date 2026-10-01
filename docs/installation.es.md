# Instalación

- [Introducción](#introduction)
- [Requisitos](#requirements)
- [Instalar el plugin](#installing-the-plugin)
- [Preparar la página](#preparing-the-page)
    - [Declarar el parámetro de paso](#declaring-the-step-parameter)
- [Valores por defecto globales](#global-defaults)
- [Ejecutar las pruebas](#running-the-tests)

<a name="introduction"></a>
## Introducción

El wizard es un plugin de Winter CMS con un único componente. No hay paso de compilación, ni
directorio de assets publicados, ni configuración de empaquetador de JavaScript: el componente
inyecta su propia hoja de estilos y su script a través del Asset Combiner del framework, y
ambos se pueden desactivar.

<a name="requirements"></a>
## Requisitos

| Requisito | Versión |
|---|---|
| PHP | 8.2 o superior |
| Winter CMS | 1.2 o superior |

Winter CMS 1.2 incluye Snowboard, al que escucha el script del wizard. El plugin no admite la
era jQuery de Winter 1.0.

<a name="installing-the-plugin"></a>
## Instalar el plugin

Requiere el paquete con Composer:

```shell
composer require zimudec/wn-wizard-plugin
```

El plugin se registra como `zimudec.wizard` y expone un único alias de componente, `wizard`.

<a name="preparing-the-page"></a>
## Preparar la página

Agregue el componente a cualquier página del CMS:

```html
title = "Encuesta"
url = "/encuesta/:step?"
layout = "default"
==
{% component 'wizard' %}
```

Configure los pasos desde el Inspector. En el nivel 0 el componente no necesita nada más para
renderizar y avanzar un recorrido lineal de pasos; consulte
[Configuración](configuration.es.md#level-0-properties-only).

<a name="declaring-the-step-parameter"></a>
### Declarar el parámetro de paso

La URL de la página **debe** declarar un parámetro `step` opcional, como en
`/encuesta/:step?` más arriba. El wizard resuelve el paso actual a partir de ese parámetro y
construye todas las URLs de navegación a partir de la misma página.

Una página sin `:step?` lanza una `ConfigurationException` traducida en la primera petición:

> The page URL must declare an optional step parameter (e.g. "/:step?") for the wizard to work. Add ":step?" at the end of the page URL.

Es una decisión deliberada. Un wizard cuyos pasos no son direccionables no se puede enlazar,
no se puede reanudar tras una sesión expirada y no se puede proteger del lado del servidor.

<a name="global-defaults"></a>
## Valores por defecto globales

El plugin incluye una clave de configuración, el tiempo de inactividad por defecto en minutos:

```php
<?php
// plugins/zimudec/wizard/config/config.php

return [
    'ttl' => 30,
];
```

Para cambiar el valor por defecto de toda la aplicación, cree la misma clave en el directorio
de configuración de la aplicación en lugar de editar el archivo del paquete. Winter CMS aplica
en cascada el valor de la aplicación sobre el del plugin, y su archivo sobrevive a una
actualización del paquete:

```php
<?php
// config/zimudec/wizard.php

return [
    'ttl' => 15,
];
```

También puede limitar el valor por defecto a un solo entorno con
`config/<entorno>/zimudec/wizard.php`.

> [!NOTE]
> Este valor por defecto se aplica únicamente cuando un wizard deja el TTL sin declarar. Tanto
> la propiedad del componente `ttl` como el método del builder `->ttl()` lo sobrescriben por
> wizard; consulte [Ajustes de todo el wizard](configuration.es.md#wizard-wide-settings).

<a name="running-the-tests"></a>
## Ejecutar las pruebas

El plugin incluye una suite de pruebas que cubre los tres niveles de configuración:

```shell
php artisan winter:test -p zimudec.wizard
```

A continuación, continúe con [Configuración](configuration.es.md).
