# Winter CMS Wizard

Un componente de wizard multipágina para [Winter CMS](https://wintercms.com): tres niveles de
configuración, validación por paso y un almacén de sesión con la privacidad por delante. Sin
paso de compilación, sin jQuery y sin Bootstrap: el framework sirve los assets con huella de
contenido y la hoja de estilos vive en una capa en cascada que su tema puede sobrescribir.

<img width="770" height="403" alt="Captura de pantalla_2026-09-30_23-23-43" src="https://github.com/user-attachments/assets/dc7282c1-f961-4cd2-86ae-50f0ca36d3f3" />

## Requisitos

- PHP 8.2 o superior
- Winter CMS 1.2 o superior

## Instalación

```shell
composer require zimudec/wn-wizard-plugin
```

## Un primer wizard

Agregue el componente a una página cuya URL declare un parámetro de paso opcional y defina los
pasos desde el Inspector:

```html
title = "Encuesta"
url = "/encuesta/:step?"
layout = "default"
==
{% component 'wizard' %}
```

Con `steps = "step-1|step-2|step-3"` el wizard atiende `/survey/step-1` a `/survey/step-3`,
controla los pasos que el usuario aún no alcanzó y avanza por sí solo.

Para declarar los pasos, sus campos y sus reglas de validación en código, llame a `define()`
desde la página. El ejemplo completo es el
[ejemplo rápido de configuración](docs/configuration.es.md#configuration-quickstart).

## Documentación

La documentación completa está indexada en
[docs/documentation.es.md](docs/documentation.es.md). Cada página tiene una versión en inglés
con el mismo nombre.

- [Instalación](docs/installation.es.md)
- [Configuración](docs/configuration.es.md)
- [Validación](docs/validation.es.md)
- [Navegación](docs/navigation.es.md)
- [Sesión](docs/session.es.md)
- [Control programático](docs/programmatic-control.es.md)
- [Personalización](docs/customization.es.md)
- [Privacidad](docs/privacy.es.md)
- [Accesibilidad](docs/accessibility.es.md)
- [Estándares y referencias](docs/standards.es.md)
- [Guía de actualización](docs/upgrade.es.md)

## Actualizar desde 1.x

2.0 es una versión con cambios que rompen compatibilidad y la rama `1.x` queda congelada para
correcciones críticas. La configuración, los handlers, la clave de sesión y los parciales
cambian todos; consulte la [guía de actualización](docs/upgrade.es.md) con ejemplos lado a
lado.

## Pruebas

```shell
php artisan winter:test -p zimudec.wizard
```

## Licencia

MIT — consulte [LICENSE](LICENSE).
