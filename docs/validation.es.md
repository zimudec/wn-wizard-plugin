# Validación

- [Introducción](#introduction)
- [El pipeline de envío](#the-submit-pipeline)
- [Reglas](#rules)
    - [Qué ven las reglas](#what-the-rules-see)
    - [Mensajes personalizados](#custom-messages)
- [Validación extra](#extra-validation)
- [Los dos argumentos de datos](#the-two-data-arguments)
    - [El hook de commit](#the-commit-hook)
    - [El handler](#the-handler)
- [Revalidar pasos previos](#revalidating-previous-steps)
    - [Solo al enviar](#on-submit-only)
    - [También frenando la entrada](#also-gating-the-entry)
    - [El rebote](#the-bounce)
- [Eventos de ciclo](#cycle-events)
- [Métodos de paso](#step-methods)

<a name="introduction"></a>
## Introducción

Todos los pasos del wizard se validan con el mismo pipeline, y es el pipeline el que hace
avanzar el flujo. Su página declara las reglas y, donde las reglas no pueden expresar la
comprobación, closures que se ejecutan en un punto definido del envío. El wizard es dueño de
las transiciones: valida, guarda, redirige y controla el acceso, y su código nunca termina una
petición.

Toda la validación pasa por el validador de Laravel, de modo que están disponibles todas sus
reglas, mensajes y archivos de idioma. El plugin no agrega ninguna regla propia.

<a name="the-submit-pipeline"></a>
## El pipeline de envío

Un único handler atiende todos los pasos —`wizard::onNext`— y las reglas que aplica son las
del paso que está en la URL, nunca el nombre del handler que pidió el cliente. Las fases se
ejecutan siempre en este orden:

1. **Lista blanca.** La petición se reduce a las claves que declaró el paso. Consulte
   [Qué se persiste](session.es.md#what-persists).
2. **Reglas base.** `rules` se ejecutan sobre los datos acumulados de los pasos previos
   combinados con la entrada permitida. Un fallo informa errores por campo y no se guarda nada.
3. **Revalidación de los pasos previos.** Solo cuando el paso lo declara, y solo cuando las
   reglas base pasaron. Consulte [Revalidar pasos previos](#revalidating-previous-steps).
4. **`wizard.beforeValidate`** se dispara. Consulte [Eventos de ciclo](#cycle-events).
5. **`extraValidation`.** Su closure, con la imagen acumulada completa en el segundo argumento.
   Agregue errores para bloquear; devuelva un array para persistir datos derivados.
6. **`commit`.** Su closure, una vez por envío exitoso, para el efecto secundario que no debe
   repetirse. Lanza una `ValidationException` para bloquear.
7. **`handler`.** Su closure, si se declaró. Devuelva un `RedirectResponse` para elegir el
   destino; no devuelva nada para el paso siguiente automático.
8. **`wizard.afterCommit`** se dispara.
9. **Persistencia.** La entrada permitida y los datos derivados se guardan bajo el código del
   paso.
10. **`wizard.stepCompleted`** se dispara.
11. **Avance o finalización.** El navegador se redirige al paso siguiente, o a `finishUrl` tras
    el último.

Como los errores de campo del paso actual se informan antes de que corra la revalidación, un
usuario con una dirección de correo incorrecta ve el error del correo y no un rebote a un paso
que ya completó.

<a name="rules"></a>
## Reglas

`->rules()` recibe un array de reglas de Laravel estándar, indexado por el nombre del campo:

```php
->step('buyer', 'Datos del comprador')
->fields([
    ['name' => 'name', 'label' => 'Nombre completo'],
    ['name' => 'email', 'label' => 'Correo electrónico'],
])
->rules([
    'name' => 'required|min:2',
    'email' => 'required|email',
])
```

Las claves de las reglas son también la lista blanca de persistencia. Un campo declarado en
`fields` sin regla se guarda y nunca se valida; una regla sin campo declarado se guarda y no
renderiza ningún input.

<a name="what-the-rules-see"></a>
### Qué ven las reglas

Las reglas base se ejecutan sobre los datos acumulados de los pasos previos **combinados con**
la entrada permitida del paso actual. Una regla del paso actual que nombra una clave que un
paso anterior guardó valida por tanto el valor guardado, que es lo que necesita una
restricción entre pasos:

```php
->step('review', 'Revisar y pagar')
->rules([
    // "store" lo guardó el paso "branch".
    'store' => 'required|in:center,north',
])
```

El validador solo ve datos permitidos, porque la lista blanca se aplica antes. Una clave a la
que no llega ninguna regla ni ningún campo declarado se descarta antes de validar, de modo que
tampoco puede fallar una regla.

<a name="custom-messages"></a>
### Mensajes personalizados

`->messages()` recibe pares `campo.regla` y sobrescribe el mensaje del framework para ese paso:

```php
->rules(['email' => 'required|email'])
->messages([
    'email.required' => 'Necesitamos su correo electrónico para enviar el comprobante',
    'email.email'    => 'Eso no parece una dirección de correo electrónico',
])
```

Los mensajes del paso tienen precedencia sobre los archivos de idioma de la aplicación. Para
mensajes que apliquen a todos los wizards de la aplicación, sobrescriba el archivo de idioma;
consulte [Personalizar los textos](customization.es.md#customize-the-texts).

> [!NOTE]
> Una clave anidada aparece en el mensaje con su propia ruta con puntos: un fallo en
> `items.0.sku` dice "The items.0.sku field is required.". Sobrescriba el array `attributes`
> en `lang/<código>/validation.php` para dar a esas claves un nombre legible.

<a name="extra-validation"></a>
## Validación extra

`->extraValidation()` recibe una closure con el validador y los datos. Úsela para las
comprobaciones que una regla no puede expresar: una sucursal que existe, cantidades que
cuadran, un dominio que no acepta.

```php
->extraValidation(function ($validator, $data) {
    if (!isset($this->stores()[$data['store'] ?? ''])) {
        $validator->errors()->add('store', 'Esa sucursal no está disponible');
    }
})
```

Agregar un error para un campo que **no** está en el DOM no es un error ni una advertencia. El
script del wizard recoge los mensajes sin input correspondiente en el contenedor de errores de
formulario en la parte superior del formulario y le da el foco; consulte
[Errores de formulario](customization.es.md#form-level-errors).

Devuelva un array para persistir **datos derivados** para los pasos posteriores. Los valores se
combinan con los datos guardados del propio paso, de modo que un paso posterior los lee desde
la imagen acumulada:

```php
->extraValidation(function ($validator, $data) {
    $cart = $this->cart([...]);

    if ($cart === null) {
        $validator->errors()->add('bundles', 'Elija al menos un producto');
    }

    return $cart ? ['totals' => $cart] : [];
})
```

<a name="the-two-data-arguments"></a>
## Los dos argumentos de datos

`commit()` y `handler()` reciben **dos** argumentos de datos, y la división es la misma en
ambos:

| Argumento | Qué contiene |
|---|---|
| Primero | **Este paso**: su entrada permitida combinada con los datos derivados que devolvió su propio `extraValidation` |
| Segundo | **Solo los pasos previos**: los datos acumulados de todos los pasos anteriores a este |

`extraValidation()` es distinto: su único argumento de datos es la imagen **completa**, los
datos acumulados de los pasos previos combinados con la entrada de este paso. No hay segundo
argumento porque no hay nada que desambiguar.

> [!WARNING]
> Leer los datos de este paso desde el segundo argumento falla en silencio. Obtiene el valor
> que un paso anterior guardó bajo el mismo nombre, y nada informa del error. En el ejemplo
> del checkout, el paso `review` lee `totals` del segundo argumento porque el paso `items` lo
> derivó, y una orden creada desde el primer argumento no llevaría total alguno.

<a name="the-commit-hook"></a>
### El hook de commit

`commit()` se ejecuta exactamente una vez por envío exitoso, antes del avance. Es el lugar
para el efecto secundario que no debe repetirse: crear un registro, encolar un correo, llamar
a una API de pago. Lanzar una `ValidationException` bloquea el avance e informa su error.

```php
->commit(function ($data, $previous) {
    Order::create([
        'total'  => $previous['totals']['subtotal'],
        'method' => $data['payment_method'],
    ]);
})
```

> [!NOTE]
> El hook se ejecuta una vez por envío, no una vez por sesión. Un envío doble se cancela en el
> navegador, y una guarda del lado del cliente no puede descartar un duplicado concurrente:
> lleve su propia clave de idempotencia en el efecto secundario. Consulte
> [Idempotencia](standards.es.md#idempotency).

<a name="the-handler"></a>
### El handler

`handler()` es opcional y decide el destino. No devuelva nada y el wizard avanza al paso
siguiente por sí solo. Devuelva un `RedirectResponse` —de `jumpTo()` o `redirectTo()`— y el
wizard omite tanto la redirección automática como la limpieza de finalización, y permanece
activo.

Eso es lo que necesita un flujo de pago: el estado debe sobrevivir mientras la pasarela
responde. Consulte [Un salto condicional](programmatic-control.es.md#a-conditional-skip-inside-a-step-handler).

```php
->handler(function ($data, $previous) {
    if ($this->wizard->stepCurrent() < 2) {
        return $this->wizard->redirectTo('branch');
    }
})
```

<a name="revalidating-previous-steps"></a>
## Revalidar pasos previos

Los datos de la sesión pueden quedar obsoletos: una sucursal cierra, un descuento caduca, una
campaña cambia. Un paso puede revalidar todos los que lo preceden con las reglas propias de
cada uno, y rebotar al usuario al primer paso que ya no pasa.

> [!WARNING]
> La revalidación está **deshabilitada por defecto**. Ni `revalidateOnSubmit()` ni
> `revalidate()` se ejecutan a menos que el paso los declare. Un wizard cuyos pasos no declaran
> ninguno nunca revalida nada.

<a name="on-submit-only"></a>
### Solo al enviar

`->revalidateOnSubmit()` revalida los pasos previos cuando el usuario envía. Las visitas no
pagan el costo, y el envío detecta datos previos rotos mediante el rebote.

Esta es la opción recomendada: la misma integridad a menor costo.

<a name="also-gating-the-entry"></a>
### También frenando la entrada

`->revalidate()` ejecuta la misma revalidación y además frena la **entrada**: al entrar al paso
revalida los pasos previos antes de mostrar el formulario, de modo que el usuario nunca llena
un formulario que no podrá avanzar.

Úselo cuando el paso puede alcanzarse con datos previos rotos: un enlace profundo, un salto de
campaña, una sesión que sobrevivió a un cambio de stock. Como la guarda de entrada bloquea la
visita, un paso omitido cuyas reglas sean `required` y cuyos datos estén vacíos detendrá al
usuario en la puerta; prefiera `revalidateOnSubmit()` en flujos que omiten pasos.

<a name="the-bounce"></a>
### El rebote

Una revalidación fallida se comporta como un "volver" al primer paso que ya no pasa:

- Se borran los datos del paso que falló y de todos los posteriores.
- Se conservan los datos de los pasos anteriores.
- El usuario aterriza en el paso que falló para volver a llenarlo.

Si falla el **primer** paso, todo el estado se vacía y el flujo se reinicia, exactamente como
una sesión expirada.

Cada paso previo se revalida con sus propias reglas **y** con su propio `extraValidation`, que
recibe los datos acumulados de los pasos que lo preceden: el mismo contexto que tenía la
primera vez. Una closure entre pasos se comporta por tanto igual en la primera validación que
en la revalidación. Un paso que pasa refresca sus datos derivados en la sesión.

> [!NOTE]
> El rebote descarta la cadena de consulta. Reejecutar una campaña de enlace profundo con el
> mismo prefill inválido haría rebotar al usuario entre la landing y la revalidación.

<a name="cycle-events"></a>
## Eventos de ciclo

Tres eventos permiten que otros plugins observen un envío. Cada uno recibe la instancia del
componente, el `StepConfig` del paso y los datos que se describen a continuación.

```php
Event::listen('wizard.beforeValidate', function ($wizard, $step, $data) { /* ... */ });
Event::listen('wizard.afterCommit', function ($wizard, $step, $data) { /* ... */ });
Event::listen('wizard.stepCompleted', function ($wizard, $step, $data) { /* ... */ });
```

| Evento | Se dispara | Los datos que lleva |
|---|---|---|
| `wizard.beforeValidate` | después de la revalidación, antes de `extraValidation` | los datos acumulados de los pasos previos combinados con la entrada permitida |
| `wizard.afterCommit` | después de `commit()` y `handler()`, **antes** de que el paso se persista | el estado acumulado **sin** el paso que se está enviando |
| `wizard.stepCompleted` | **después** de que el paso se persista | el estado acumulado **incluyendo** el paso recién completado |

Los dos últimos son hermanos con contratos de datos distintos, y los nombres invitan la
expectativa equivocada. Si un listener necesita los datos que el usuario acaba de enviar, use
`stepCompleted`. `afterCommit` existe para reaccionar a que los hooks se hayan ejecutado, no a
que los datos se estén guardando.

Lanzar una `ValidationException` en `wizard.beforeValidate` es la forma admitida de bloquear el
avance desde otro plugin. Lanzarla en los otros dos no es un mecanismo de bloqueo: en
`wizard.afterCommit` aborta la petición antes de que el paso se guarde, y en
`wizard.stepCompleted` la aborta después de que el paso se guardó y el puntero de progreso se
movió.

<a name="step-methods"></a>
## Métodos de paso

Todo lo que devuelve `->step($code, $title)`. Los ajustes de todo el wizard viven en el propio
builder; consulte [Ajustes de todo el wizard](configuration.es.md#wizard-wide-settings).

| Método | Qué hace |
|---|---|
| `->fields($fields)` | Los campos del paso. Consulte [Claves de campo](configuration.es.md#field-keys) |
| `->rules($rules)` | Reglas de validación de Laravel. Sus claves son la lista blanca de persistencia |
| `->messages($messages)` | Mensajes para este paso, sintaxis `campo.regla` |
| `->extraValidation($closure)` | Comprobaciones manuales y datos derivados |
| `->commit($closure)` | Efectos secundarios, una vez por envío exitoso |
| `->handler($closure)` | Selector de destino opcional |
| `->view($partial)` | Renderizar el paso con un parcial propio en lugar del shell por defecto |
| `->revalidate($bool = true)` | Revalidar los pasos previos y frenar la entrada |
| `->revalidateOnSubmit()` | Revalidar los pasos previos solo al enviar |
| `->keepData($bool = true)` | Conservar el estado del wizard tras completar el último paso |
| `->end()` | Registrar el paso y volver al builder del wizard |

A continuación, continúe con [Navegación](navigation.es.md).
