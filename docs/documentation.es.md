# Documentación del Wizard

El wizard tiene tres niveles de configuración. El nivel 0 configura un recorrido lineal desde
el Inspector sin escribir código. El nivel 1 declara los pasos, sus campos y sus reglas desde
la página. El nivel 2 agrega hooks sobre el mismo pipeline de envío. Los tres niveles se
ejecutan sobre un único núcleo, de modo que lo aprendido en el nivel 0 sigue siendo válido en
el nivel 2.

Lea estas páginas en orden la primera vez. Después, cada una se sostiene por sí sola, y el
checkout completo de [Configuración](configuration.es.md#configuration-quickstart) es el
ejemplo que el resto de la documentación extiende.

**Prólogo**

- [Guía de actualización](upgrade.es.md) — migrar un wizard existente de 1.x a 2.0

**Primeros pasos**

- [Instalación](installation.es.md) — requisitos, el paquete de Composer y la URL de página que el wizard necesita
- [Configuración](configuration.es.md) — los tres niveles, las propiedades del componente y la referencia del builder

**Lo esencial**

- [Validación](validation.es.md) — reglas, mensajes, validación extra, revalidación, el hook de commit y los eventos
- [Navegación](navigation.es.md) — cómo se resuelve un paso desde la URL, cómo se controla el acceso y cómo avanza el flujo
- [Sesión](session.es.md) — qué guarda el wizard, durante cuánto tiempo y cómo leerlo y escribirlo

**Profundizando**

- [Control programático](programmatic-control.es.md) — handlers AJAX propios: consultas, carritos, guardas y saltos condicionales
- [Personalización](customization.es.md) — estilo, assets, sobrescritura de vistas y textos

**Seguridad**

- [Privacidad](privacy.es.md) — qué hace el wizard con los datos y la lista de verificación previa a publicar
- [Accesibilidad](accessibility.es.md) — lo que viene incluido y lo que solo usted puede aportar

**Referencia**

- [Estándares y referencias](standards.es.md) — el estándar detrás de cada comportamiento, con su cita
- [Changelog](../CHANGELOG.md) — todos los cambios publicados

<a name="api-index"></a>
## Índice de la API

Toda la superficie pública del plugin y la página que la documenta.

| Superficie | Referencia |
|---|---|
| Propiedades del componente (11) | [Propiedades del componente](configuration.es.md#component-properties) |
| Métodos de wizard del builder (6) | [Ajustes de todo el wizard](configuration.es.md#wizard-wide-settings) |
| Métodos de paso del builder (11) | [Métodos de paso](validation.es.md#step-methods) |
| API de sesión (7 llamadas) | [API de sesión](session.es.md#session-api) |
| API de navegación (2 llamadas) | [API de navegación](navigation.es.md#navigation-api) |
| Eventos de ciclo (3) | [Eventos de ciclo](validation.es.md#cycle-events) |
| Variables de vista (6) | [Variables de vista](customization.es.md#view-variables) |
| Parciales (6) | [Sobrescritura de vistas](customization.es.md#view-overrides) |
| Archivo de configuración (1 clave) | [Valores por defecto globales](installation.es.md#global-defaults) |
| Excepciones traducidas (6) | [Errores de configuración](configuration.es.md#configuration-errors) |
