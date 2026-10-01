# Navegación

- [Introducción](#introduction)
- [El paso en la URL](#the-step-in-the-url)
- [Control de acceso](#gating)
    - [Antes del primer paso](#before-the-first-step)
    - [Pasos desconocidos e inalcanzables](#unknown-and-unreachable-steps)
    - [Después del último paso](#after-the-last-step)
    - [Flujos expirados y reiniciados](#expired-and-restarted-flows)
- [Construcción de las URLs](#building-the-urls)
- [La cadena de consulta](#the-query-string)
- [El indicador de pasos](#the-step-indicator)
- [API de navegación](#navigation-api)

<a name="introduction"></a>
## Introducción

Un paso del wizard es una URL, y el wizard resuelve el paso desde esa URL en el servidor antes
de renderizar nada. El acceso a un paso que el usuario aún no ha alcanzado se rechaza con una
redirección, no con un formulario: el flujo no se puede recorrer editando la barra de
direcciones, y un enlace guardado o compartido aterriza en el último paso que el usuario tiene
permitido ver.

Toda URL de navegación se deriva de la URL de la página y de los códigos de paso configurados,
de modo que el componente no necesita configuración de rutas y la página solo necesita el
parámetro `:step?` descrito en
[Instalación](installation.es.md#declaring-the-step-parameter).

<a name="the-step-in-the-url"></a>
## El paso en la URL

El código del paso es el parámetro `:step` de la URL de la página. Dada una página en
`/checkout/:step?` y los códigos `branch`, `items`, `buyer`, `review`, las cuatro direcciones
son:

| URL | Paso |
|---|---|
| `/checkout/branch` | 1 — Elija su sucursal |
| `/checkout/items` | 2 — Elija sus productos |
| `/checkout/buyer` | 3 — Datos del comprador |
| `/checkout/review` | 4 — Revisar y pagar |

El código es lo que se pasa a cada llamada de navegación, y lo que contiene
`wizard.steps[].code` en la vista. La posición en esa lista es lo que la sesión registra.

<a name="gating"></a>
## Control de acceso

La sesión guarda un puntero de progreso: el índice del último paso que el usuario validó. Un
wizard nuevo tiene el puntero en `0`, de modo que solo el primer paso es alcanzable; enviarlo
mueve el puntero a `1`, y el segundo paso pasa a ser alcanzable.

| Petición | Resultado |
|---|---|
| Sin parámetro `:step` | Redirección al primer paso |
| Un paso en el puntero o anterior | Se renderiza |
| Un paso posterior al puntero | Redirección al paso del puntero |
| Un código que no corresponde a ningún paso | Redirección al paso del puntero |

La redirección es un `RedirectResponse` nativo del framework: `X_WINTER_REDIRECT` para una
petición AJAX y un `302` simple para un envío de formulario sin JavaScript.

<a name="before-the-first-step"></a>
### Antes del primer paso

Una petición sin parámetro `:step` se redirige al primer paso conservando la cadena de
consulta. Entrar al flujo en `/checkout` lleva al navegador a `/checkout/branch?ref=...`, que
es lo que hace expresable un enlace profundo hacia el medio de un flujo; consulte
[Enlace profundo con prefill](programmatic-control.es.md#deep-link-with-prefill).

<a name="unknown-and-unreachable-steps"></a>
### Pasos desconocidos e inalcanzables

Ambos casos se resuelven al mismo lugar, el último paso válido. Un código que no corresponde a
nada y un paso que el usuario no ha alcanzado se tratan de forma idéntica, y ninguno informa
un error al usuario: el wizard no tiene noción de un 404 para un paso, solo de una posición
que puede servir.

<a name="after-the-last-step"></a>
### Después del último paso

Enviar el último paso guarda sus datos, vacía el estado y redirige a `finishUrl`. Los datos
están disponibles durante toda la petición, de modo que una página de confirmación que los lea
durante ese mismo render todavía los encuentra; la petición siguiente ya no.

Recargar la última página reinicia el flujo. Cuando la página de confirmación necesita los
datos entre peticiones, declare `->keepData()` en el último paso: el estado sobrevive hasta el
siguiente reinicio del flujo. Consulte
[Borrado al finalizar](session.es.md#cleared-on-finish).

<a name="expired-and-restarted-flows"></a>
### Flujos expirados y reiniciados

Dos condiciones vacían el estado y reinician en el primer paso: un tiempo de inactividad
expirado y un estado marcado como finalizado. Ambas se comprueban al renderizar la página y
nuevamente al enviar, de modo que un formulario publicado mucho después del tiempo de espera no
se aplica a un estado obsoleto.

El vaciado es total. No hay ninguna vía que reviva un wizard expirado, y nunca se muestra al
usuario un paso con datos que ya no tienen una sesión a la que pertenecer.

<a name="building-the-urls"></a>
## Construcción de las URLs

El componente construye cada URL a partir del nombre de archivo de la página y del código del
paso, de modo que los enlaces funcionan en cualquier host, esquema o subdirectorio:

```php
$url = \Cms\Classes\Page::url($this->page->fileName, ['step' => 'items']);
// https://example.com/checkout/items
```

La vista los recibe listos para usar:

| Variable | Qué contiene |
|---|---|
| `wizard.urls.next` | La URL del paso siguiente, o `null` en el último paso |
| `wizard.urls.prev` | La URL del paso anterior, o `null` en el primer paso |
| `wizard.urls.finish` | El `finishUrl` configurado |
| `wizard.steps[].url` | La URL propia de cada paso |

Un `null` en `next` es lo que convierte el botón de envío en un botón de finalizar, de modo
que sobrescriba el parcial del botón solo si quiere cambiar esa regla.

<a name="the-query-string"></a>
## La cadena de consulta

Las redirecciones automáticas difieren en lo que conservan, y la diferencia es deliberada:

| Redirección | Cadena de consulta |
|---|---|
| Al primer paso (sin `:step`, expirado, desconocido, inalcanzable) | Se conserva |
| Al paso siguiente tras un envío | **Se descarta** |
| A `finishUrl` | **Se descarta** |
| Desde `redirectTo()` o `jumpTo()` | Solo los parámetros que usted pase |

Una campaña de enlace profundo entra por la raíz del wizard, y sus parámetros deben sobrevivir
al rebote interno, de modo que la primera fila los conserva. Las redirecciones de envío los
descartan a propósito: el paso acaba de responderse y una referencia de campaña obsoleta no
debe seguir al usuario hacia un paso que no describe. Cuando un avance deba transportar un
parámetro, devuelva un `jumpTo()` con la consulta en lugar de confiar en la vía automática.

<a name="the-step-indicator"></a>
## El indicador de pasos

El shell por defecto renderiza la lista de pasos desde `wizard.steps`. Cada entrada lleva un
`state`:

| Estado | Cuándo | Marcado |
|---|---|---|
| `completed` | Antes del paso actual | Un enlace cuando `retainData` está activo; en caso contrario, un `<span>` |
| `current` | El paso que se está atendiendo | Un `<span>` con `aria-current="step"` |
| `pending` | Después del paso actual | Un `<span>` |

Un paso completado no es clicable por defecto, que es la misma decisión que el borrado al
retroceder: un indicador editable invita al usuario a revisiting un paso, y revisitar un paso es
justo lo que borra los datos posteriores. `retainData` cambia ambas cosas juntas, de modo que el
indicador y la retención siempre coinciden.

> [!NOTE]
> `state` se calcula a partir del paso que se atiende, no del puntero de progreso. Tras un
> `jumpTo('review')` los tres primeros pasos se renderizan como `completed` aunque el usuario no
> los haya llenado. Eso es correcto para un enlace profundo prellenado, y conviene saberlo
> antes de derivar lógica de negocio del estado.

Para renderizar el progreso en otro lugar, consulte
[Variables de vista](customization.es.md#view-variables).

<a name="navigation-api"></a>
## API de navegación

Dos llamadas, ambas devuelven un `RedirectResponse` y ambas fallan de inmediato cuando el
código del paso no existe.

| Llamada | Qué hace |
|---|---|
| `->redirectTo($stepCode, $query = [])` | Redirige sin tocar los datos guardados ni el puntero de progreso. La guarda de renderizado de un ciclo de vida de página: "el carrito está vacío, de vuelta al paso 1" |
| `->jumpTo($stepCode, $data = [], $query = [])` | Persiste `$data`, desbloquea todos los pasos hasta el objetivo y redirige a él. La API de enlaces profundos y saltos |

`redirectTo()` es lo que devuelven una guarda de `onEnd()` y un handler AJAX. `jumpTo()` es lo
que devuelve un `handler()` cuando quiere elegir el destino, y lo que usa un `onInit()` para
prellenar un flujo; consulte [Control programático](programmatic-control.es.md).

> [!NOTE]
> `jumpTo()` desbloquea pero no valida. Las reglas de los pasos omitidos nunca se ejecutan, y
> solo las reglas del paso objetivo se ejecutan en su propio envío. Prellene lo que leen los
> pasos posteriores, incluidos sus datos derivados, o declare `->revalidateOnSubmit()` en el
> objetivo para que un requisito ausente aterrice al usuario en el paso que lo posee.

A continuación, continúe con [Sesión](session.es.md).
