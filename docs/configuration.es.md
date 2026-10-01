# Configuración

- [Introducción](#introduction)
- [Ejemplo rápido de configuración](#configuration-quickstart)
    - [La página del checkout](#the-checkout-page)
    - [Qué queda en la sesión](#what-ends-up-in-the-session)
- [Nivel 0: solo propiedades](#level-0-properties-only)
- [Propiedades del componente](#component-properties)
    - [La sintaxis de lista](#the-list-syntax)
- [Nivel 1: el builder](#level-1-the-builder)
- [Nivel 2: hooks](#level-2-hooks)
- [Ajustes de todo el wizard](#wizard-wide-settings)
    - [Precedencia](#precedence)
- [Campos](#fields)
    - [Claves de campo](#field-keys)
    - [Tipos de campo](#field-types)
- [Errores de configuración](#configuration-errors)

<a name="introduction"></a>
## Introducción

Un wizard es una lista de pasos, un pipeline de envío que los valida y los hace avanzar, y un
almacén de sesión que guarda lo que el usuario ingresó. Configura la lista de pasos de una de
tres maneras, y las otras dos mitades del wizard funcionan igual en las tres.

El nivel 0 define los pasos desde el Inspector y no escribe código. El nivel 1 declara los
pasos, sus campos y sus reglas de validación desde la página. El nivel 2 agrega hooks sobre el
mismo pipeline de envío. Elija el nivel más bajo que cubra lo que necesita y siga leyendo
cuando deje de cubrirlo.

<a name="configuration-quickstart"></a>
## Ejemplo rápido de configuración

Para ver la configuración completa de una vez, aquí está un checkout de cuatro pasos en
`/checkout/:step?`: elegir sucursal, elegir productos, ingresar el comprador, revisar y pagar.
Todas las demás páginas de esta documentación extienden este ejemplo.

<a name="the-checkout-page"></a>
### La página del checkout

```html
title = "Checkout"
url = "/checkout/:step?"
layout = "default"
==
<?
use Zimudec\Wizard\Support\Builder as WizardBuilder;

// El catálogo son sus datos: en un proyecto real, un modelo. Esta página lo
// mantiene en funciones simples para que el ejemplo se lea sin base de datos.
function stores(): array
{
    return ['center' => 'Downtown', 'north' => 'Northside'];
}

function products(): array
{
    return ['widget' => 15, 'gadget' => 40];
}

// Un único dueño de la forma del carrito: la validación extra del paso y el
// handler auxiliar de abajo lo construyen aquí. Devuelve null si está vacío.
function cart(array $quantities): array
{
    $items = [];
    $subtotal = 0;

    foreach ($quantities as $code => $quantity) {
        $quantity = (int) $quantity;

        if ($quantity < 1 || !isset($this->products()[$code])) {
            continue;
        }

        $items[$code] = ['quantity' => $quantity, 'price' => $this->products()[$code]];
        $subtotal += $quantity * $this->products()[$code];
    }

    return $items ? ['subtotal' => $subtotal, 'items' => $items] : null;
}

function onInit()
{
    $this->wizard->define(function (WizardBuilder $builder) {
        $builder
            ->finishUrl('/checkout/complete')

            ->step('branch', 'Elija su sucursal')
            ->fields([
                ['name' => 'store', 'type' => 'select', 'label' => 'Sucursal', 'options' => $this->stores()],
            ])
            ->rules(['store' => 'required'])
            ->extraValidation(function ($validator, $data) {
                if (!isset($this->stores()[$data['store'] ?? ''])) {
                    $validator->errors()->add('store', 'Esa sucursal no está disponible');
                }
            })
            ->end()

            ->step('items', 'Elija sus productos')
            ->fields([
                ['name' => 'quantity_widget', 'label' => 'Widgets'],
                ['name' => 'quantity_gadget', 'label' => 'Gadgets'],
            ])
            ->rules([
                'quantity_widget' => 'nullable|integer|min:0',
                'quantity_gadget' => 'nullable|integer|min:0',
            ])
            ->extraValidation(function ($validator, $data) {
                $cart = $this->cart([
                    'widget' => $data['quantity_widget'] ?? 0,
                    'gadget' => $data['quantity_gadget'] ?? 0,
                ]);

                if ($cart === null) {
                    // Una regla de negocio sin campo en el DOM llega al
                    // contenedor de errores de formulario en lugar de descartarse.
                    $validator->errors()->add('bundles', 'Elija al menos un producto');
                }

                // Devolver un array persiste datos derivados para los pasos posteriores.
                return $cart ? ['totals' => $cart] : [];
            })
            ->end()

            ->step('buyer', 'Datos del comprador')
            ->fields([
                ['name' => 'name', 'label' => 'Nombre completo'],
                ['name' => 'email', 'label' => 'Correo electrónico'],
            ])
            ->rules(['name' => 'required', 'email' => 'required|email'])
            ->end()

            ->step('review', 'Revisar y pagar')
            ->fields([
                ['name' => 'payment_method', 'type' => 'select', 'label' => 'Método de pago', 'options' => [
                    'card' => 'Tarjeta', 'transfer' => 'Transferencia',
                ]],
                ['name' => 'terms', 'label' => 'Acepto los términos'],
            ])
            ->rules(['payment_method' => 'required', 'terms' => 'required'])
            ->commit(function ($data, $previous) {
                Order::create([
                    // 'totals' lo derivó el paso "items", de modo que llega en el
                    // segundo argumento. Consulte Los dos argumentos de datos.
                    'total' => $previous['totals']['subtotal'],
                    'method' => $data['payment_method'],
                ]);
            })
            ->keepData()
            ->end();
    });
}

// Handler AJAX auxiliar del paso "items": el carrito mostrado junto al formulario.
function onCartData()
{
    $data = $this->wizard->data();

    if (empty($data['totals'])) {
        return $this->wizard->redirectTo('branch');
    }

    return ['cart' => $data['totals']];
}
?>
==
{% component 'wizard' %}
```

`define()` recibe un `Zimudec\Wizard\Support\Builder`. Cada `->step()` devuelve un builder de
paso, las llamadas entre `->step()` y `->end()` configuran ese paso, y `->end()` vuelve al
builder del wizard para que pueda declarar el paso siguiente.

> [!NOTE]
> `$this->wizard` es la instancia del componente, alcanzada mediante el alias que declara la
> página en `{% component 'wizard' %}`. Una página que nombre el componente de otro modo debe
> usar ese nombre.

<a name="what-ends-up-in-the-session"></a>
### Qué queda en la sesión

El checkout deja esto, una entrada por paso:

| Paso | Enviado y persistido | Derivado |
|---|---|---|
| `branch` | `store` | — |
| `items` | `quantity_widget`, `quantity_gadget` | `totals` |
| `buyer` | `name`, `email` | — |
| `review` | `payment_method`, `terms` | — |

No se guarda nada más. Un campo que el paso no declaró se descarta, y también un archivo
subido; consulte [Qué se persiste](session.es.md#what-persists).

<a name="level-0-properties-only"></a>
## Nivel 0: solo propiedades

El nivel 0 configura todo el recorrido desde el Inspector, sin código en la página. Agregue el
componente, defina `steps` con los códigos de los pasos, defina `titles` con sus nombres, y el
wizard renderiza el indicador, el formulario y los botones, y avanza por sí solo:

```html
title = "Encuesta"
url = "/encuesta/:step?"
layout = "default"
==
{% component 'wizard' %}
```

Con `steps = "step-1|step-2|step-3"` el wizard atiende `/survey/step-1`, `/survey/step-2` y
`/survey/step-3`, en ese orden. Sin un paso en la URL redirige al primero.

Agregue `fields` y cada paso renderiza un input de texto por nombre. El nivel 0 no infiere
ninguna regla de validación: un campo declarado se persiste y se rellena de nuevo cuando el
usuario vuelve, y nunca se rechaza. Cuando necesite una regla, pase al nivel 1.

<a name="component-properties"></a>
## Propiedades del componente

Las once propiedades que el Inspector expone, con sus valores por defecto:

| Propiedad | Tipo | Por defecto | Qué hace |
|---|---|---|---|
| `steps` | string | `''` | Códigos de paso, separados por `\|` o `,`. El orden define el flujo |
| `titles` | string | `''` | Títulos de los pasos en el mismo orden. Un paso sin título muestra su código |
| `fields` | string | `''` | Nombres de campo renderizados como inputs de texto en **todos** los pasos |
| `finishUrl` | string | `/` | Dónde aterriza el navegador al completar el último paso |
| `ttl` | string | `''` | Tiempo de inactividad en minutos. Vacío toma el valor global, `0` lo deshabilita |
| `wipeOnBack` | checkbox | `true` | Borrar los datos de los pasos posteriores cuando el usuario retrocede |
| `retainData` | checkbox | `false` | Conservar esos datos y volver clicables los pasos completados |
| `encrypted` | checkbox | `false` | Cifrar los datos persistidos en la sesión |
| `keepSteps` | string | `''` | Códigos de paso cuyos datos sobreviven al retroceso |
| `css` | checkbox | `true` | Cargar la hoja de estilos incorporada |
| `js` | checkbox | `true` | Cargar el script incorporado |

`steps` y `titles` son posicionales: el tercer título corresponde al tercer código. Un paso
cuyo título falta, o es igual a su código, se renderiza sin encabezado, que es lo que
conviene cuando un código ya es una etiqueta legible.

<a name="the-list-syntax"></a>
### La sintaxis de lista

`steps`, `titles`, `fields` y `keepSteps` aceptan la misma notación: entradas separadas por una
barra vertical o una coma, cada una recortada, y las entradas vacías descartadas. Todo esto
declara los mismos tres pasos:

```
step-1|step-2|step-3
step-1, step-2, step-3
step-1 | step-2 |
```

<a name="level-1-the-builder"></a>
## Nivel 1: el builder

Una página que llama a `define()` configura los pasos en código y sobrescribe por completo las
propiedades: las propiedades del componente `steps`, `titles` y `fields` dejan de leerse. Los
ajustes de todo el wizard declarados en
[Ajustes de todo el wizard](#wizard-wide-settings) siguen funcionando como respaldo, ajuste
por ajuste.

El builder ofrece exactamente un punto de entrada:

| Llamada | Qué hace |
|---|---|
| `$this->wizard->define($closure)` | Declara el wizard. La closure recibe el builder; no devuelva nada |

<a name="level-2-hooks"></a>
## Nivel 2: hooks

El nivel 2 es el nivel 1 más las closures sobre el pipeline de envío. Tres se declaran por
paso:

| Llamada | Qué hace |
|---|---|
| `->extraValidation($closure)` | Comprobaciones que las reglas no pueden expresar. Agregue errores para bloquear el avance; devuelva un array para persistir datos derivados |
| `->commit($closure)` | Efectos secundarios, exactamente una vez por envío exitoso, antes del avance |
| `->handler($closure)` | Opcional. Devuelva un `RedirectResponse` para elegir el destino; no devuelva nada para el paso siguiente |

La [documentación de validación](validation.es.md#the-submit-pipeline) cubre qué recibe cada
closure y en qué orden las ejecuta el pipeline.

<a name="wizard-wide-settings"></a>
## Ajustes de todo el wizard

Estos seis ajustes corresponden al wizard completo y no a un paso. Cada uno tiene un método
del builder y una propiedad del componente, y cada uno es inoperante si no se declara ninguno.

| Método del builder | Propiedad | Por defecto | Qué hace |
|---|---|---|---|
| `->finishUrl($url)` | `finishUrl` | `/` | Dónde aterriza el último paso |
| `->ttl($minutes)` | `ttl` | global, 30 | Tiempo de inactividad. `0` lo deshabilita; omitido toma el valor global |
| `->wipeOnBack($bool)` | `wipeOnBack` | `true` | Borrar los datos de los pasos posteriores al retroceder |
| `->retainData($bool)` | `retainData` | `false` | Conservar esos datos y volver clicables los pasos completados |
| `->encrypted($bool)` | `encrypted` | `false` | Cifrar los datos persistidos en la sesión |
| `->keepSteps($codes)` | `keepSteps` | `[]` | Códigos de paso cuyos datos sobreviven al retroceso |

`->keepSteps()` acepta un array de códigos o la misma cadena separada por `|` que acepta la
propiedad. Un código que no sea un paso del wizard lanza una excepción cuando la configuración
se resuelve por primera vez.

<a name="precedence"></a>
### Precedencia

La precedencia es **por ajuste, no en bloque**. Un ajuste que la página declara a través del
builder gana. Un ajuste que la página deja sin declarar cae a la propiedad del componente. Un
ajuste que ninguno declara toma el valor por defecto.

```php
$this->wizard->define(function (WizardBuilder $builder) {
    $builder
        ->ttl(10)              // declarado: gana sobre la propiedad "ttl"
        // 'encrypted' no está declarado: se aplica la propiedad "encrypted"
        ->step('only', 'Solo un paso');
});
```

> [!NOTE]
> Esta es la razón por la que un ajuste de privacidad como `encrypted` es alcanzable desde una
> página que usa `define()`. El comportamiento anterior leía la configuración del builder en
> lugar de las propiedades siempre que se llamaba a `define()`, lo que dejaba cuatro de los
> seis ajustes silenciosamente inalcanzables justo en las páginas que los necesitaban.

<a name="fields"></a>
## Campos

<a name="field-keys"></a>
### Claves de campo

Un campo es un array. Solo `name` es obligatoria.

| Clave | Por defecto | Qué hace |
|---|---|---|
| `name` | — | El nombre del input. También es la clave que usan las reglas de validación y bajo la que se guarda el valor |
| `type` | `'text'` | Qué parcial lo renderiza. Consulte [Tipos de campo](#field-types) |
| `label` | `''` | La etiqueta visible |
| `options` | `[]` | Las opciones de un `select`, como `valor => etiqueta` |
| `attributes` | `[]` | Atributos HTML adicionales, como `nombre => valor` |

```php
->step('buyer', 'Datos del comprador')
->fields([
    ['name' => 'name', 'label' => 'Nombre completo'],
    ['name' => 'email', 'label' => 'Correo electrónico', 'attributes' => ['autocomplete' => 'email']],
    ['name' => 'store', 'type' => 'select', 'label' => 'Sucursal', 'options' => $this->stores()],
])
```

Declare `autocomplete` en los campos que recopilan información sobre el usuario; consulte
[Accesibilidad](accessibility.es.md).

<a name="field-types"></a>
### Tipos de campo

El parcial despachador conoce dos tipos: `select` renderiza un `<select>` y **cualquier otro
tipo renderiza un input de texto**. Un tipo declarado `checkbox` o `date` se renderiza por
tanto como un input de texto, sin error alguno.

Para agregar un tipo, sobrescriba también el despachador, no solo el nuevo parcial; consulte
[Sobrescritura de vistas](customization.es.md#view-overrides).

<a name="configuration-errors"></a>
## Errores de configuración

Una configuración inválida lanza una `Zimudec\Wizard\Support\ConfigurationException` en la
primera petición que la resuelve, con un mensaje traducido. Cada una de las seis nombra el paso
o la página responsable, de modo que el mensaje por sí solo indica qué cambiar.

| Clave del mensaje | Causa |
|---|---|
| `steps_empty` | Ni la propiedad `steps` ni `define()` declararon un paso |
| `step_param_missing` | La URL de la página no tiene `:step?` |
| `step_code_empty` | Se declaró un paso sin código |
| `field_name_empty` | Se declaró un campo sin nombre |
| `step_not_found` | Una llamada nombró un código de paso que el wizard no declara |
| `keep_step_unknown` | `keepSteps` nombra un código que no es un paso del wizard |

A continuación, continúe con [Validación](validation.es.md).
