# Control programático

- [Introducción](#introduction)
- [Handlers auxiliares](#auxiliary-handlers)
- [Proteger un handler](#guarding-a-handler)
    - [La guarda de renderizado](#the-render-guard)
- [Un handler auxiliar que persiste lo que encuentra](#an-auxiliary-handler-that-persists-what-it-finds)
- [Enlace profundo con prefill](#deep-link-with-prefill)
- [Un salto condicional dentro de un handler de paso](#a-conditional-skip-inside-a-step-handler)
- [Saltar pasos que necesitan datos](#jumping-over-steps-that-need-data)

<a name="introduction"></a>
## Introducción

El wizard es dueño de su propio envío: la validación, el almacenamiento, la redirección al
paso siguiente y el control de acceso a un paso que el usuario aún no alcanzó. Lo que no es
suyo es el handler AJAX que su página declara para una consulta, un panel de carrito o un
autoguardado.

Esta página cubre cómo escribir esos handlers contra el estado del wizard y qué guardas
necesitan. Las llamadas en sí están documentadas en
[API de sesión](session.es.md#session-api) y
[API de navegación](navigation.es.md#navigation-api); los ejemplos extienden el checkout
declarado en el [ejemplo rápido de configuración](configuration.es.md#configuration-quickstart)
y muestran solo la parte que agregan.

<a name="auxiliary-handlers"></a>
## Handlers auxiliares

Un handler auxiliar es un handler de página ordinario. Winter lo ejecuta directamente y el
wizard no lo intercepta:

```php
// Handler auxiliar del paso "items": el carrito mostrado junto al formulario.
function onCartData()
{
    $data = $this->wizard->data();

    if (empty($data['totals'])) {
        return $this->wizard->redirectTo('branch');
    }

    return ['cart' => $data['totals']];
}
```

Devolver un array lo entrega a Snowboard como respuesta del handler; devolver un
`RedirectResponse` se convierte en `X_WINTER_REDIRECT`.

> [!NOTE]
> El componente 1.x controlaba todos los handlers AJAX mediante el evento
> `cms.ajax.beforeRunHandler`, de modo que un handler sin guarda no podía invocarse fuera de
> orden. En 2.0 el control es explícito: un handler es un handler de página y declara la guarda
> que necesita.

<a name="guarding-a-handler"></a>
## Proteger un handler

`requireData()` protege un handler y devuelve sus datos en una sola llamada. Devuelve los datos
combinados cuando el paso es alcanzable y contiene las claves nombradas. Si no, devuelve una
redirección al último paso alcanzado:

```php
function onCartData()
{
    $data = $this->wizard->requireData('items', ['totals']);

    if ($this->wizard->needsRedirect($data)) {
        return $data;
    }

    return ['cart' => $data['totals']];
}
```

<a name="the-render-guard"></a>
### La guarda de renderizado

Una guarda sobre la propia página necesita `onEnd()`, porque `onInit()` no puede redirigir: el
CMS descarta su valor de retorno. `onEnd()` se ejecuta después de los componentes y puede
devolver una respuesta:

```php
// GET /checkout/review — la página de revisión necesita un carrito con datos.
function onEnd()
{
    if ($this->param('step') === 'review' && empty($this->wizard->data()['totals'])) {
        return $this->wizard->redirectTo('branch');
    }
}
```

La guarda de renderizado es la más importante: sin ella, un paso sin guardas renderiza un
formulario cuyo envío fallaría después, y el usuario llena una página que nunca iba a aceptar
lo que ingresara.

<a name="an-auxiliary-handler-that-persists-what-it-finds"></a>
## Un handler auxiliar que persiste lo que encuentra

Un resultado de consulta no es un campo de formulario. Persístalo a través de la API de sesión
para que un paso posterior pueda leerlo:

```php
// Handler AJAX, invocado desde la página mientras el usuario está en /checkout/buyer.
function onLookupUser()
{
    $data = $this->wizard->data();
    $user = User::where('email', $data['email'] ?? '')->first();

    // Escrito en el paso "buyer", de modo que el paso de revisión lo lee junto
    // con el resto de los datos de ese paso.
    $this->wizard->saveData(['user' => ['id' => $user?->id]], 'buyer');

    return ['exists' => $user !== null];
}
```

`saveData()` no valida nada, que es el punto: estas claves no son campos de formulario y una
petición manipulada no debe poder alcanzarlas a través de la lista blanca del pipeline.

<a name="deep-link-with-prefill"></a>
## Enlace profundo con prefill

Los parámetros de URL prellenan datos y desbloquean el paso solicitado:

```php
// URL de entrada — GET /checkout?store=center
function onInit()
{
    if (request()->ajax() || !get('store')) {
        return;
    }

    // Indexado por código de paso. Desbloquear un paso no ejecuta sus reglas,
    // de modo que el prefill debe llevar lo que leen los pasos posteriores.
    $this->wizard->jumpTo('items', ['branch' => ['store' => get('store')]]);
}
```

Tres cosas hacen que esto funcione, y conviene separarlas:

- El `jumpTo()` se ejecuta por sus **efectos**: persiste el prefill y desbloquea el paso
  solicitado.
- Su valor de retorno se **descarta**, porque el CMS ignora lo que devuelve `onInit()`. El
  usuario sigue aterrizando en la URL que solicitó, y la redirección propia del wizard al
  renderizar transporta la cadena de consulta.
- Para **mover** al usuario al paso objetivo en lugar de eso, hace falta una redirección real:
  escriba el estado en `onInit()` y devuelva el mismo `jumpTo()` desde `onEnd()`.

<a name="a-conditional-skip-inside-a-step-handler"></a>
## Un salto condicional dentro de un handler de paso

Un paso cuyo propósito ya está cubierto puede omitir el resto. Aquí el handler de `branch`
envía al usuario directo a la revisión cuando la URL pidió un checkout exprés:

```php
// POST /checkout/branch?express=1&product=gadget
->step('branch', 'Elija su sucursal')
->fields([['name' => 'store', 'type' => 'select', 'label' => 'Sucursal', 'options' => $this->stores()]])
->rules(['store' => 'required'])
->handler(function ($data) {
    $product = (string) get('product');
    $cart = $this->cart([$product => 1]);

    if (get('express') !== '1' || $cart === null) {
        return;                       // el wizard avanza por sí solo
    }

    // El prefill que habría producido el paso "items" omitido: la cantidad
    // cruda y el carrito derivado que lee la guarda de revisión.
    return $this->wizard->jumpTo('review', [
        'items' => ['quantity_' . $product => 1, 'totals' => $cart],
    ], ['express' => '1']);
})
->end()
```

| Argumento | Qué hace |
|---|---|
| `'review'` | El paso objetivo. El puntero de progreso se mueve a él y el usuario es redirigido allí |
| `['items' => …]` | El prefill del paso omitido, indexado por código de paso. Incluya los **datos derivados** que leen los pasos posteriores: la guarda de renderizado de la revisión comprueba `totals`, de modo que un salto sin ellos rebota al usuario al paso 1. También funcionan claves libres (`['buyer' => ['express' => true]]` es una marca, no un campo de formulario) |
| `['express' => '1']` | La cadena de consulta que se anexa a la URL de redirección |

Un handler que devuelve una redirección toma el control de la navegación: se omiten tanto la
redirección automática al paso siguiente como la limpieza de finalización, y el estado del
wizard permanece activo. Eso es lo que necesita un flujo de pago mientras la pasarela responde.

Los datos enviados de `branch` se persisten igualmente: el pipeline los guarda en cualquier caso.

<a name="jumping-over-steps-that-need-data"></a>
## Saltar pasos que necesitan datos

`jumpTo()` desbloquea pero no valida. Las reglas de los pasos omitidos nunca se ejecutan durante
el salto, y solo las reglas del paso objetivo se ejecutan en su propio envío. Antes de saltar
un paso que recopila datos obligatorios, elija una de estas dos:

- **Prellene** lo que necesita, incluidos los **datos derivados** que leen los pasos
  posteriores. El handler de arriba calcula el carrito en el momento del salto precisamente por
  eso.
- **Tolere la ausencia** en el código posterior (`$data['buyer'] ?? []`), y solo cuando ninguna
  guarda dependa de ello: una guarda que compruebe los datos faltantes rebota el paso objetivo
  al llegar.

Declarar `->revalidateOnSubmit()` en un flujo que omite pasos es la red de seguridad
recomendada: el usuario aterriza en el primer paso que ya no pasa, con intactos los datos
anteriores. Consulte [El rebote](validation.es.md#the-bounce).

> [!NOTE]
> `->revalidate()` agrega la guarda de entrada a eso, y un paso omitido cuyas reglas sean
> `required` y cuyos datos estén vacíos detendrá la visita en la puerta. Prefiera
> `revalidateOnSubmit()` salvo que quiera bloquear también esas visitas.

A continuación, continúe con [Personalización](customization.es.md).
