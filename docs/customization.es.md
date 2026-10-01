# Personalización

- [Introducción](#introduction)
- [Estilo](#styling)
    - [La capa en cascada](#the-cascade-layer)
    - [Tokens de diseño](#design-tokens)
    - [Descargar la hoja de estilos](#unloading-the-stylesheet)
- [El script](#the-script)
    - [Gestión del foco](#focus-management)
    - [Errores de formulario](#form-level-errors)
    - [La guarda contra el envío doble](#the-double-submission-guard)
    - [Reemplazar el comportamiento del script](#replacing-the-script-behavior)
- [Assets](#assets)
- [Sobrescritura de vistas](#view-overrides)
    - [Variables de vista](#view-variables)
    - [Agregar un tipo de campo](#adding-a-field-type)
- [Escribir un partial de paso propio](#write-a-custom-step-partial)
- [Personalizar los textos](#customize-the-texts)
    - [Mensajes por paso](#per-step-messages)
    - [Sobrescrituras de idioma de la aplicación](#application-language-overrides)

<a name="introduction"></a>
## Introducción

Todo lo que el plugin renderiza está diseñado para ser reemplazado: la hoja de estilos vive
en una capa en cascada, el script escucha eventos del DOM en lugar de posearlos, cada parcial
tiene una sobrescritura por tema y cada cadena de texto proviene de un archivo de idioma.

Esta página cubre las cuatro superficies: la hoja de estilos, el script, las vistas y los
textos.

<a name="styling"></a>
## Estilo

El plugin incluye `assets/css/wizard.css` con una apariencia por defecto autónoma: un
indicador de pasos en forma de pestañas, campos con etiqueta, errores en línea y acciones
alineadas a la derecha. No requiere ningún framework de CSS y se adapta a
`prefers-color-scheme: dark` y a `prefers-reduced-motion`.

Su layout necesita la etiqueta `{% styles %}` para que el componente inyecte la hoja.

<a name="the-cascade-layer"></a>
### La capa en cascada

Todas las reglas de la hoja de estilos viven dentro de `@layer wizard`. Una hoja de estilos de
su tema no está dentro de ninguna capa por defecto, y los estilos sin capa siempre ganan a los
que están en capa, de modo que sus reglas prevalecen sin `!important` y sin una pelea de especificidad.

<a name="design-tokens"></a>
### Tokens de diseño

La apariencia depende de las propiedades personalizadas `--wizard-*`. Vuelva a declararlas en
cualquier lugar de su tema para reajustarla:

```css
.wizard {
    --wizard-accent: #7c3aed;
    --wizard-radius: 0.75rem;
}
```

<a name="unloading-the-stylesheet"></a>
### Descargar la hoja de estilos

Con `css = 0` en el Inspector el componente no inyecta nada, y su tema aporta todo el
estilizado sobre el mismo marcado semántico. Los nombres de clase son estables y están
documentados por el marcado siguiente.

<a name="the-script"></a>
## El script

El componente carga `assets/js/wizard.js`, un script sin dependencias con tres funciones. Escucha
los eventos del DOM que la capa de peticiones de Snowboard despacha sobre el formulario, de
modo que funciona sin importar cómo el tema inicialice el singleton de Snowboard, y no hace
nada en absoluto sin JavaScript, porque el mismo marcado es un formulario funcional sin él.

<a name="focus-management"></a>
### Gestión del foco

Cuando un envío AJAX falla la validación, el script marca cada control inválido con
`aria-invalid="true"` y mueve el foco al primero. Un envío que comienza borra las marcas del
intento anterior.

Los campos que busca los encuentra por id: consulta `#` más la clave de error, escapada con
`CSS.escape()`. Por eso el `id` del input debe ser igual a la clave de error que devuelve el
servidor; consulte [Escribir un partial de paso propio](#write-a-custom-step-partial).

<a name="form-level-errors"></a>
### Errores de formulario

Un error sin input correspondiente no tiene bolsa de campo donde Snowboard pueda pintar. Por
eso el shell del wizard incluye un contenedor de formulario:

```html
<div class="wizard__form-error" data-wizard-errors role="alert" tabindex="-1" hidden>
    <p class="wizard__form-error__title">Fix the following errors:</p>
    <div class="wizard__form-error__list" data-wizard-errors-list></div>
</div>
```

El script recoge cada mensaje cuya clave no corresponde a ningún elemento
`[data-validate-error]` y lo renderiza allí, luego muestra el contenedor y le da el foco
cuando no aplica ningún error de campo.

> [!NOTE]
> El contenedor está presente desde la carga de la página. Las bolsas por campo no: el
> `FormValidation` de Snowboard elimina cada elemento `[data-validate-error]` al arrancar y
> deja un comentario marcador, reinsertando el elemento vacío solo cuando llega un mensaje. Un
> `role="alert"` sobre una región que se agrega al DOM junto con su mensaje se anuncia en la
> práctica, pero la técnica [ARIA19](https://www.w3.org/WAI/WCAG22/Techniques/aria/ARIA19)
> pide que la región exista desde el inicio, y solo el contenedor de formulario lo cumple.
> Consulte [Asociación de errores y resumen](standards.es.md#error-association-and-summary).

Las claves de error que comienzan con guion bajo nunca se renderizan. Es una convención del
lado del cliente para sus propios handlers: un canal de control, no algo que el plugin
produzca:

```php
// Un handler propio puede devolver una clave con prefijo de guion bajo para
// pasar un valor al cliente sin mostrarlo.
return ['_retry_after' => 30];
```

<a name="the-double-submission-guard"></a>
### La guarda contra el envío doble

Mientras hay un envío en curso, el formulario lleva `aria-busy="true"` y un atributo
`data-wizard-submitting`, y cualquier envío posterior del mismo formulario se cancela en la
fase de captura, antes de que el handler delegado de Snowboard a nivel de ventana pueda crear
una segunda petición. El botón de envío nunca se deshabilita.

El bloqueo se libera en `ajaxDone`, `ajaxFail` y `ajaxAlways`, de modo que toda vía de salida
libera el formulario, incluida una petición cancelada, que solo dispara `ajaxAlways`. Una
guarda que se liberara solo en `ajaxDone` y `ajaxFail` dejaría el formulario permanentemente
incapaz de enviar.

El estado visual de carga queda en manos del tema, mediante el atributo `data-attach-loading`
que lleva el botón de envío.

<a name="replacing-the-script-behavior"></a>
### Reemplazar el comportamiento del script

Ponga `js = 0` para descargar el script e implemente el comportamiento usted mismo sobre los
mismos eventos del DOM:

```js
document.querySelectorAll('form[data-request-validate]').forEach((form) => {
    form.addEventListener('ajaxFail', (event) => {
        const fields = (event.request.responseData || {}).X_WINTER_ERROR_FIELDS || {};
        // Su estrategia de foco: el primer campo inválido, un resumen de errores…
    });
});
```

Para extender el comportamiento incorporado en lugar de reemplazarlo, mantenga `js` activo y
agregue sus propios listeners. El script solo asigna `aria-invalid` y mueve el foco, de modo
que los listeners adicionales nunca entran en conflicto con él.

<a name="assets"></a>
## Assets

No hay paso de compilación ni query de versión. El Asset Combiner del framework incluye una
huella del contenido en la URL combinada, la sirve con una cabecera de caché de un año y la
minimiza en producción cuando el modo de depuración está desactivado. El script se sirve con
`defer`, de modo que nunca bloquea el análisis del HTML.

Ambos assets se desactivan desde el Inspector:

| Propiedad | Por defecto | Efecto de desactivarla |
|---|---|---|
| `css` | `true` | No se inyecta nada; su tema estila el mismo marcado |
| `js` | `true` | Sin gestión de foco, sin errores de formulario y sin guarda contra el envío doble |

<a name="view-overrides"></a>
## Sobrescritura de vistas

Coloque un parcial con el mismo nombre bajo `partials/wizard/` de su tema. El plugin no se
modifica y la versión del tema prevalece.

| Parcial | Renderiza |
|---|---|
| `partials/wizard/default.htm` | El shell del paso: indicador, formulario, campos y botones |
| `partials/wizard/nav.htm` | El indicador de pasos |
| `partials/wizard/buttons.htm` | Los botones de navegación |
| `partials/wizard/field.htm` | El despachador de campos |
| `partials/wizard/field_text.htm` | Un campo de texto |
| `partials/wizard/field_select.htm` | Un campo select |

Un paso también puede reemplazar el shell entero con `->view('mi-parcial')`. Ese parcial se
renderiza en lugar de `default.htm`, dentro del mismo envoltorio `wizard` que usa el shell:

```php
->step('beneficiaries', 'Beneficiarios')->view('buy/beneficiaries')->end()
```

<a name="view-variables"></a>
### Variables de vista

Dentro de los parciales del propio plugin usted tiene las variables de la página más estas:

| Variable | Qué contiene |
|---|---|
| `{{ __SELF__ }}` | La instancia del componente |
| `{{ __SELF__.currentStepFields() }}` | Los campos del paso actual |
| `{{ wizard.steps }}` | La lista de pasos: `code`, `title`, `url`, `state` |
| `{{ wizard.stepCurrentName }}` | El título del paso actual |
| `{{ wizard.stepNumber }}` | La posición del paso actual, basada en 1 |
| `{{ wizard.urls }}` | Las URLs `next`, `prev` y `finish` |
| `{{ wizard.data }}` | Los datos acumulados del wizard |
| `{{ wizard.retainData }}` | Si los pasos completados son clicables |

`wizard.data` contiene solo datos del usuario; los metadatos internos del wizard nunca llegan
a una plantilla.

El tutorial de formularios multipágina de WAI pide el progreso primero en el título de la
página, seguido del nombre del paso. El layout dispone de ambos valores:

```html
<title>{% put title %}Paso {{ wizard.stepNumber }} de {{ wizard.steps|length }}: {{ wizard.stepCurrentName }}{% endput %}</title>
```

<a name="adding-a-field-type"></a>
### Agregar un tipo de campo

El despachador decide qué parcial renderiza un campo, y conoce dos tipos. Para agregar un
tercero debe sobrescribir **tanto** el despachador como el nuevo parcial: un
`field_checkbox.htm` por sí solo nunca se alcanza:

```html
{# themes/<tema>/partials/wizard/field.htm #}
{% if field.type == 'select' %}
    {% partial '@field_select.htm' field=field value=value %}
{% elseif field.type == 'checkbox' %}
    {% partial '@field_checkbox.htm' field=field value=value %}
{% else %}
    {% partial '@field_text.htm' field=field value=value %}
{% endif %}
```

Consulte [Tipos de campo](configuration.es.md#field-types) para ver qué hace hoy un tipo
declarado y desconocido.

<a name="write-a-custom-step-partial"></a>
## Escribir un partial de paso propio

Un paso que necesita un marcado que el shell genérico no puede dibujar declara
`->view('mi/partial')`. Ese parcial reemplaza todo el shell del paso, de modo que también es
dueño del formulario, de los botones y del indicador de pasos.

Cinco propiedades de un parcial así no son evidentes.

**`__SELF__` está vacío en un parcial del tema.** Solo está definido dentro de los parciales del
propio componente. Use el alias del componente que declara la página, de modo que el formulario
pida el handler literalmente como `wizard::onNext`; de lo contrario el navegador rechaza el
selector mal formado.

**El `id` del input debe ser igual a la clave de error que devuelve el servidor.** El script de
foco busca el campo como `#` más esa clave, y las bolsas de error comparan `data-validate-for`
contra las mismas claves. Para una grilla enviada como `items[0][sku]`, la clave es
`items.0.sku`:

```html
<input type="text" name="items[0][sku]" id="items.0.sku" data-validate-error="items.0.sku">
```

Hacer coincidir el id con el nombre deja a cada bolsa sin encontrar su campo, y al script de
foco sin encontrar el input.

**Lea los datos de `wizard.data`, nunca de un método de página.** En un render completo
`wizard` es el array que expone el componente; en un rerender de parcial es el objeto
componente. Una llamada a un método como `this.rows()` devuelve vacío en ese segundo contexto
sin levantar nada. `{{ wizard.data|default({}) }}` cubre ambos.

**Use `??` para los valores y `|default` para los booleanos.** El filtro `default` también se
dispara con la cadena vacía, de modo que un campo que el usuario acaba de limpiar volvería al
valor que guardó el envío anterior. `??` solo cede cuando la clave está ausente.

**Un `for` de dos variables necesita un mapa, no una lista de pares.**
`{% for key, label in [('a', 'Uno')] %}` es un error de sintaxis;
`{% for key, label in {'a': 'Uno'} %}` itera correctamente.

<a name="customize-the-texts"></a>
## Personalizar los textos

Todos los textos que el wizard muestra son reemplazables, incluidos los mensajes de validación.
Existen dos mecanismos, del más preciso al más amplio.

<a name="per-step-messages"></a>
### Mensajes por paso

Páselos al builder con la sintaxis `campo.regla`. Se aplican a ese paso y tienen precedencia
sobre cualquier otra fuente:

```php
->rules(['field1' => 'required', 'field2' => 'required|email'])
->messages([
    'field1.required' => 'Ingrese su nombre completo',
    'field2.email'    => 'Esa no parece una dirección de correo electrónico',
])
```

<a name="application-language-overrides"></a>
### Sobrescrituras de idioma de la aplicación

Winter resuelve los mensajes genéricos de validación desde el namespace `system`, de modo que
puede sobrescribirlos para toda la aplicación desde el directorio `lang/` sin tocar el plugin.
Para cambiar todos los mensajes `required`, cree `lang/es/system/validation.php`:

```php
<?php

return [
    'required' => 'Este campo es obligatorio',
    'attributes' => [
        'field1' => 'su nombre completo', // reemplaza :attribute en los mensajes
    ],
];
```

La misma estructura sobrescribe las cadenas propias del plugin. Cree
`lang/es/zimudec/wizard/lang.php` e incluya solo las claves que quiera cambiar; el archivo
original aporta el resto:

```php
<?php

return [
    'buttons' => [
        'next' => 'Continuar',
    ],
    'nav' => [
        'steps' => 'Progreso',
    ],
];
```

Las cadenas propias del plugin se distribuye en inglés, español y francés.

> [!TIP]
> Un mensaje que dice qué hacer —"Ingrese su nombre completo"— indica al usuario cómo
> corregir el problema, no solo que existe uno. Prefiera textos específicos para los campos que
> a sus usuarios les cuestan; consulte [Accesibilidad](accessibility.es.md).
