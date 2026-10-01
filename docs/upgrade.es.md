# Guía de actualización

- [Introducción](#introduction)
- [El patrón de 1.x](#the-1x-pattern)
- [El mismo wizard en 2.0](#the-same-wizard-in-20)
- [Qué cambia](#what-changes)
- [Cambios de comportamiento](#behavior-changes)

<a name="introduction"></a>
## Introducción

2.0 es una versión con cambios que rompen compatibilidad. La configuración, los handlers, la
clave de sesión y las vistas cambian todas. La rama `1.x` queda congelada para correcciones
críticas solamente.

Esta guía cubre la superficie pública del plugin. La forma de sus propios datos —carritos,
catálogos, consultas— es suya en ambas versiones, de modo que aquí no aparece nada de eso. El
changelog lista el resto: [CHANGELOG.md](../CHANGELOG.md).

<a name="the-1x-pattern"></a>
## El patrón de 1.x

Un array `steps` declarado en `onInit`, un handler de página por paso y el formulario escrito a
mano en la página:

```html
title = "Wizard Example"
url = "/wizard-example/:step?"
==
<?
function onInit()
{
    $this->wizard->steps = [
        ['step' => 'step1', 'name' => 'Step 1', 'forms' => [
            'onStep1' => [
                'validation' => ['field1' => 'required', 'field2' => 'required'],
                'extra_validation' => function ($validator, $fields, $prevValidationsData) {
                    if ($fields['field1'] != 'hello') {
                        $validator->errors()->add('field1', 'The value of this field must be "hello"');
                    }
                    return ['user' => ['id' => 1, 'names' => 'User']];
                },
            ],
        ]],
        ['step' => 'step2', 'name' => 'Step 2', 'validatePrevSteps' => true],
    ];
}

function onStep1()
{
    $data = $this->wizard->formsValidate();

    return redirect($data['stepNext']);
}
?>
==
{% if wizard.stepCurrent == 'step1' %}
    <form data-request="onStep1" data-request-validate>
        {% partial '@input_text.htm' label="Field 1 *" name="field1" %}
        {% partial '@nav_buttons.htm' %}
    </form>
{% endif %}
```

<a name="the-same-wizard-in-20"></a>
## El mismo wizard en 2.0

Un builder declarado en `onInit`, un handler genérico para todos los pasos y ningún marcado de
formulario en la página:

```html
title = "Wizard Example"
url = "/wizard-example/:step?"
==
<?
use Zimudec\Wizard\Support\Builder as WizardBuilder;

function onInit()
{
    $this->wizard->define(function (WizardBuilder $builder) {
        $builder
            ->step('step1', 'Step 1')
            ->fields([
                ['name' => 'field1', 'label' => 'Field 1 *'],
                ['name' => 'field2', 'label' => 'Field 2 *'],
            ])
            ->rules(['field1' => 'required', 'field2' => 'required'])
            ->extraValidation(function ($validator, $data) {
                if ($data['field1'] != 'hello') {
                    $validator->errors()->add('field1', 'The value of this field must be "hello"');
                }
                return ['user' => ['id' => 1, 'names' => 'User']];
            })
            ->end()

            ->step('step2', 'Step 2')
            ->revalidate()
            ->end();
    });
}
?>
==
{% component 'wizard' %}
```

La closure de `extra_validation` recibe un argumento menos. En 1.x recibía los campos del
propio paso y, por separado, los datos acumulados de los pasos previos. En 2.0 su único
argumento de datos es la unión de ambos: los datos acumulados de los pasos previos combinados
con la entrada permitida del paso actual.

<a name="what-changes"></a>
## Qué cambia

La superficie pública, y nada más.

| 1.x | 2.0 |
|---|---|
| `$this->wizard->steps = [...]`, un array declarado en `onInit` | `$this->wizard->define(fn (Builder $b) => …)` o las propiedades del componente |
| `['step' => 'code', 'name' => 'Title']` | `->step('code', 'Title')`, y la URL de la página declara `/:step?` |
| `['forms' => ['onStep1' => […]]]` más un handler de página por paso | Un handler genérico para todos los pasos; el paso que está en la URL selecciona la configuración |
| `formsValidate()` y `redirect($data['stepNext'])` | El pipeline valida y avanza por sí solo; `redirectTo()` y `jumpTo()` cuando usted elige el destino |
| `validation`, `validation_messages` | `->rules([...])`, `->messages([...])` |
| `validatePrevSteps` | `->revalidateOnSubmit()` o `->revalidate()` |
| `keep_sesion` (la errata está en la API de 1.x) | `->keepData()` |
| `lang/<idioma>/zimudec/wizard/validations.php` | Sobrescrituras del `lang/` de la aplicación; consulte [Personalizar los textos](customization.es.md#customize-the-texts) |
| La página escribe el formulario y se bifurca según `wizard.stepCurrent` | El componente renderiza el formulario; sobrescriba `partials/wizard/*` en el tema |
| `@input_text.htm`, `@input_select.htm`, `@nav_buttons.htm` | Campos declarados con `->fields()`, con `field_text.htm` y `field_select.htm` como sobrescrituras del tema |
| `wizard.fields` (la sesión cruda) | `wizard.data` |
| `wizard.prevValidationsData` | `wizard.data`, con los datos derivados combinados en ella |
| `wizard.stepNext`, `wizard.stepPrev` | `wizard.urls.next`, `wizard.urls.prev` |
| `wizard.stepCurrent` (el código del paso) | La entrada con `state == 'current'` en `wizard.steps` |
| `wizard.stepPos` | `wizard.stepNumber` |
| `wizard_steps-<pageFileName>`, un array plano | `zimudec.wizard.<page>.<alias>`, con `data` (por paso) y `meta` separados |
| `exit()`, `->send()`, `dump()` | `RedirectResponse` y `ValidationException` nativos del framework |
| `assets/js/wizard.js` con jQuery y clases de Bootstrap 4 | Snowboard, y una hoja de estilos dentro de una capa en cascada |
| Un handler AJAX controlado, mediante `cms.ajax.beforeRunHandler` | Una guarda que declara su handler; consulte [Proteger un handler](programmatic-control.es.md#guarding-a-handler) |

Un paso que necesita su propio marcado declara `->view('mi/partial')`; las convenciones que
debe seguir ese parcial están en
[Escribir un partial de paso propio](customization.es.md#write-a-custom-step-partial).

<a name="behavior-changes"></a>
## Cambios de comportamiento

Diferencias de semántica y no de nombres. Cada una le afecta únicamente bajo la condición
indicada.

- **Retroceder borra pasos completos.** 1.x borraba las claves declaradas como campos de
  validación, de modo que los datos que un paso derivaba sobrevivían al retroceso. 2.0 borra
  el propio paso, de modo que todo lo que derivó un paso posterior se va con él. Esto le afecta
  si un paso calcula datos de los que depende un paso al que el usuario puede volver. Nombre
  los pasos cuyos datos deben sobrevivir con `->keepSteps(['beneficiaries'])`; use
  `retainData` cuando quiera que todos los pasos posteriores sobrevivan y que los completados
  sean clicables.
- **La persistencia se declara, no se captura.** Solo se guardan las claves de reglas y los
  campos declarados de un paso. 1.x serializaba lo que la petición transportaba, de modo que
  un campo que validaba sin declarar se conservaba; ahora se descarta, junto con los archivos
  subidos.
- **La bolsa de errores no es un canal de respuesta.** Todo lo que se agregue a la bolsa hace
  fallar el paso, de modo que pasar un valor de control por una clave de error es ahora un paso
  bloqueado. Devuélvalo como dato derivado desde `extraValidation` en su lugar.
- **Una revalidación fallida aterriza en el primer paso incompleto**, no en el primer paso. Los
  datos de los pasos anteriores se conservan.
- **La revalidación está desactivada salvo que se declare.** 1.x ejecutaba
  `validatePrevSteps` cuando el paso lo pedía, y 2.0 también, pero ni `revalidateOnSubmit()`
  ni `revalidate()` se ejecutan a menos que el paso los declare, y un paso migrado sin esa
  línea deja de revalidar en silencio.
- **Las páginas de paso responden `Cache-Control: no-store`**, de modo que los valores que
  escribió un usuario no quedan en la caché del navegador.
- **Valores por defecto nuevos en los datos guardados:** un tiempo de inactividad de 30
  minutos, persistencia con lista blanca y cifrado opcional. Ninguno es una ruptura y los tres
  cambian lo que guarda su sesión.

A continuación, continúe con [Configuración](configuration.es.md).
