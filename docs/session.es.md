# Sesión

- [Introducción](#introduction)
- [Qué se persiste](#what-persists)
    - [Los dos tipos de escritura](#the-two-kinds-of-write)
    - [La lista blanca](#the-whitelist)
    - [Datos anidados y reglas con comodín](#nested-data-and-wildcard-rules)
- [La forma del almacén](#the-store-shape)
- [El tiempo de inactividad](#the-inactivity-timeout)
- [Retroceder](#going-back)
    - [Borrado al retroceder](#wipe-on-back)
    - [Conservar un paso sin conservar el resto](#keeping-one-step-without-keeping-the-rest)
    - [Conservar todo](#retaining-everything)
- [Borrado al finalizar](#cleared-on-finish)
- [Cifrado](#encryption)
- [API de sesión](#session-api)
    - [Elegir una lectura](#choosing-a-read)

<a name="introduction"></a>
## Introducción

El wizard guarda su estado en una única clave de sesión, namespaced por la página que declara
el componente y por el alias del componente, de modo que dos wizards nunca comparten estado.
Un wizard pertenece a la página que lo declara. Dentro de esa clave, los datos que el usuario
ingresó se mantienen separados de los metadatos propios del wizard, y solo los datos llegan
alguna vez a una vista.

Tres decisiones determinan qué contiene ese estado: qué claves se guardan, cuánto sobrevive y
qué ocurre con él cuando el usuario retrocede. Esta página cubre las tres. Para lo que el
wizard promete sobre los datos que maneja, consulte [Privacidad](privacy.es.md).

<a name="what-persists"></a>
## Qué se persiste

Un paso nunca persiste la petición. Persiste las claves que declaró —las claves de sus reglas
de validación y los nombres de sus campos— y descarta todo lo demás, incluidos los archivos
subidos.

<a name="the-two-kinds-of-write"></a>
### Los dos tipos de escritura

| Tipo | Escrito por | Validado | Permitido por lista blanca |
|---|---|---|---|
| Envío de formulario | El pipeline de envío | Sí, por las reglas del paso | Sí |
| API de sesión | `saveData()`, `jumpTo()` | No | No |

Un **envío de formulario** guarda lo que declaró el paso. Un campo declarado sin regla se
guarda y nunca se valida; una regla sin campo declarado se guarda y no renderiza ningún input.

Una **escritura de la API de sesión** guarda cualquier clave que usted pase —una bandera, un
ID, el resultado de una consulta— porque existe para que un handler pueda persistir lo que un
paso posterior necesita. Esas claves no son campos de formulario y nada las valida, que es
también la razón por la que una petición manipulada no puede alcanzarlas.

<a name="the-whitelist"></a>
### La lista blanca

La lista blanca se amplía a lo que declaró, nunca a lo que llegó. Dos detalles de esa regla
importan cuando un paso maneja datos anidados.

Una clave declarada cubre todo lo que hay por debajo, que es lo que evita que una regla sobre
una clave padre pierda su contenido. Esa ampliación se detiene en cuanto se declara una regla
**dentro** de la clave padre: desde ese momento, las reglas de abajo definen lo que se guarda y
los hermanos que ninguna regla nombra se descartan.

La lista blanca se aplica antes de validar, de modo que el validador nunca ve una clave que la
lista descartó, y una clave descartada no puede tampoco fallar una regla.

<a name="nested-data-and-wildcard-rules"></a>
### Datos anidados y reglas con comodín

La notación de comodines de Laravel funciona, y un envío anidado se guarda con su anidamiento
intacto. Declare la regla con el comodín y el campo con su nombre de array:

```php
->step('items', 'Sus ítems')
->fields([
    ['name' => 'items[0][sku]', 'label' => 'SKU'],
    ['name' => 'items[0][quantity]', 'label' => 'Cantidad'],
])
->rules([
    'items.*.sku' => 'required|string',
    'items.*.quantity' => 'required|integer|min:1',
])
->end()
```

Un POST de `items[0][sku]=KB-1&items[0][quantity]=2` se guarda como
`['items' => [['sku' => 'KB-1', 'quantity' => '2']]]`, y vuelve con esa forma en `commit()` y a
través de la API de sesión.

Con un comodín presente, la lista blanca se construye a partir de las claves concretas que el
propio validador expande, de modo que no puede divergir de la validación. Los cuatro casos:

| Reglas declaradas | Qué guarda un POST de `items[0][sku]` y `items[0][quantity]` |
|---|---|
| `items.*.quantity` | Solo `items.0.quantity`. `items.0.sku` se descarta: ninguna regla lo nombra |
| `items` y `items.*.quantity` | Solo `items.0.quantity`. La regla padre **no** amplía la lista blanca, porque hay una regla declarada dentro |
| `items => array` | Todo el subárbol de `items`, porque la regla del padre es lo único declarado |
| `items.*.quantity`, sin filas enviadas | Nada de ese grupo, y sin error. Un formulario donde el usuario no agregó filas no envía nada para él, y el paso avanza |

Un contenedor que llegó vacío se guarda como un array vacío en lugar de descartarse, de modo
que al leerlo se obtiene `[]` y no `null`.

> [!NOTE]
> Las filas que el usuario agrega en el navegador necesitan un parcial propio: un paso
> renderiza los campos que declaró, y una fila agregada no tiene ningún input donde
> renderizarse. Consulte [Escribir un partial de paso propio](customization.es.md#write-a-custom-step-partial)
> para las convenciones, incluido el `id` del input que vincula cada campo con su clave de
> error.

<a name="the-store-shape"></a>
## La forma del almacén

El estado vive bajo una única clave construida a partir de la página y del alias del
componente, `zimudec.wizard.<page>.<alias>`, y contiene los datos de cada paso más los
metadatos propios del wizard:

```php
[
    'data' => [
        'branch' => ['store' => 'center'],
        'items'  => ['quantity_widget' => '2', 'totals' => ['subtotal' => 30]],
    ],
    'meta' => ['stepCurrent' => 1, 'lastActivity' => 1767225600],
]
```

Las dos mitades están separadas para que un campo de formulario que por casualidad se llame
`stepCurrent` no pueda corromper el estado propio del wizard. Solo `data` llega a las
plantillas; los metadatos nunca. Las entradas de `data` se ordenan según el orden configurado
de los pasos, no según el orden en que se escribieron los datos, de modo que los límites del
borrado y el orden de la combinación son los mismos en cada petición.

<a name="the-inactivity-timeout"></a>
## El tiempo de inactividad

El wizard expira tras un periodo de inactividad, medido del lado del servidor y refrescado en
cada render y en cada envío. El valor por defecto son 30 minutos, configurado globalmente en
[Valores por defecto globales](installation.es.md#global-defaults) y sobrescribible por wizard
con la propiedad `ttl` o `->ttl()`.

Cuando el estado expira, la siguiente interacción lo vacía y reinicia el flujo en el primer
paso. Un envío que llega tras el tiempo de espera no se aplica al estado obsoleto: reinicia el
wizard en su lugar.

`0` deshabilita el tiempo de espera. Dejarlo sin declarar toma el valor global, no lo
deshabilita.

<a name="going-back"></a>
## Retroceder

Retroceder es un borrado, no una navegación. Cuando el usuario vuelve a un paso, el wizard
borra los datos de todos los pasos posteriores, que es el valor por defecto de privacidad.

<a name="wipe-on-back"></a>
### Borrado al retroceder

El límite es posicional y se resuelve en el orden configurado de los pasos. Volver al paso
`items` conserva `branch` e `items`, y elimina todo desde `buyer` en adelante.

El borrado es también la razón por la que los pasos completados del indicador no son
clicables: un indicador editable invita al usuario a recorrer un paso, y recorrer un paso es
exactamente lo que borra los datos posteriores. Consulte
[El indicador de pasos](navigation.es.md#the-step-indicator).

<a name="keeping-one-step-without-keeping-the-rest"></a>
### Conservar un paso sin conservar el resto

`keepSteps` nombra los pasos cuyos datos sobreviven al borrado. Todos los demás pasos
posteriores se borran igualmente, y los pasos completados siguen sin ser clicables. Va en el paso
que deriva los datos que un paso posterior vuelve a leer:

```php
->step('beneficiaries', 'Beneficiarios')
// Las filas que este paso escribe las vuelve a leer el paso del carro, así que
// sobreviven al retroceso. Los datos personales de cualquier paso posterior se
// borran igual.
->keepSteps(['beneficiaries'])
->end()
```

Este es el caso estrecho. Un flujo cuyo paso `items` vuelve a leer las filas que escribió un
paso `beneficiaries` necesita esas filas; no necesita los datos personales del paso posterior.
Los dos ajustes cambian cosas distintas:

| Ajuste | Datos posteriores | Pasos completados clicables |
|---|---|---|
| `wipeOnBack` (por defecto) | Se borran | No |
| `wipeOnBack(false)` | Se conservan | No |
| `retainData` | Se conservan | Sí |
| `keepSteps(['a'])` | Todo posterior al objetivo se borra excepto `a` | No |

Un código en `keepSteps` que no sea un paso del wizard lanza una excepción cuando la
configuración se resuelve por primera vez, antes de atender cualquier paso.

> [!WARNING]
> Los datos derivados no sobreviven a una navegación hacia atrás salvo que usted lo organice
> así. El borrado al retroceder elimina el paso que los calculó junto con el propio paso, **y
> nada le advierte**: la página se sigue renderizando y el siguiente envío encuentra menos
> datos de los que esperaba. Declare `keepSteps` en el paso que deriva los datos que lee un paso
> posterior.

El rebote de revalidación ignora `keepSteps`. Borra porque los datos son inválidos, y conservar
el paso que falló anularía la corrección que disparó el rebote.

<a name="retaining-everything"></a>
### Conservar todo

`retainData` conserva los datos de todos los pasos posteriores y vuelve clicables los pasos
completados. Es el compromiso opuesto, y además aplaza el borrado al finalizar; consulte
[Borrado al finalizar](#cleared-on-finish).

`keepSteps` no tiene efecto mientras `retainData` está activo, porque `retainData` ya conserva
todos los pasos posteriores.

<a name="cleared-on-finish"></a>
## Borrado al finalizar

Enviar el último paso elimina normalmente todo el estado. El borrado ocurre al final de la
petición, de modo que los datos están disponibles durante esa petición y una página de
confirmación renderizada por el destino de la redirección ya no los encuentra.

Dos declaraciones aplazan el borrado hasta el siguiente reinicio del flujo:

| Declaración | Efecto |
|---|---|
| `->keepData()` en el último paso | El estado sobrevive, para una página de confirmación que lo necesite |
| `retainData` en el wizard | Lo mismo, porque el wizard conserva datos al retroceder y se espera que los conserve también al finalizar |

Un estado marcado así se vacía en la siguiente interacción con el wizard, de modo que se
conserva para un flujo y no de forma indefinida.

<a name="encryption"></a>
## Cifrado

`encrypted` cifra cada valor persistido con la clave de la aplicación. Es opcional, y sin él
el wizard usa el almacenamiento de sesión habitual del CMS.

Dos propiedades de la implementación conviene conocer:

- La lectura tolera datos escritos antes de activar el cifrado y datos escritos con un
  `APP_KEY` anterior. Un valor indescifrable se devuelve tal como está almacenado: el formulario
  muestra ruido en lugar de que la petición falle, y el siguiente envío del usuario lo
  sobrescribe.
- Los valores se serializan al escribir y se deserializan con `allowed_classes => false` al
  leer. Guarde arrays y escalares. Un objeto vuelve incompleto una vez activado el cifrado.

<a name="session-api"></a>
## API de sesión

Los handlers AJAX propios de la página leen y escriben el estado del wizard a través del
componente. Todas las llamadas pasan por el mismo namespacing y el mismo cifrado que el
pipeline de envío, de modo que un handler no puede alcanzar un estado que el pipeline no
alcanzaría, ni guardar fuera de la clave propia del wizard.

| Llamada | Qué hace |
|---|---|
| `$this->wizard->data()` | Los datos de todos los pasos combinados, descifrados |
| `$this->wizard->stepsData()` | Los datos de todos los pasos, indexados por código de paso, sin combinar |
| `$this->wizard->stepData($stepCode = null)` | Los datos de un paso. Omita el código para el paso actual |
| `$this->wizard->meta()` | Los metadatos: puntero de progreso, última actividad, bandera de finalizado |
| `$this->wizard->stepCurrent()` | El índice del último paso validado |
| `$this->wizard->saveData($data, $stepCode = null)` | Combina valores en los datos de un paso y refresca el temporizador de inactividad |
| `$this->wizard->forgetData($keys, $stepCode = null)` | Elimina las claves nombradas. Omita el código para eliminarlas de todos los pasos |
| `$this->wizard->flush()` | Elimina el estado de este wizard, y solo el de este wizard |

`stepData()`, `saveData()` y `forgetData()` lanzan una `ConfigurationException` traducida que
nombra el código cuando este no es un paso del wizard.

`saveData()` combina: las claves que pasa se asignan y las claves que omite conservan su valor
guardado.

<a name="choosing-a-read"></a>
### Elegir una lectura

`data()` combina los pasos en el orden configurado, de modo que una clave guardada por dos
pasos resuelve a la del paso posterior. Ese es el valor por defecto correcto para un formulario
y para la lógica de negocio que quiere la imagen completa: el paso posterior es el que el
usuario llenó más recientemente.

Use `stepsData()` cuando la combinación oculte algo: un último paso que ensambla un pedido, o
un handler que necesita saber qué guarda cada paso realmente:

```php
$steps = $this->wizard->stepsData();
// ['branch' => [...], 'items' => [...], 'buyer' => [...]]
```

Use `stepData()` cuando escribió a un paso concreto y quiere leer de ese mismo paso. Leer a
través de `data()` puede devolver el valor de otro paso para la misma clave, y la escritura
parece no haber hecho nada. Es la mitad de lectura de `saveData()`:

```php
$this->wizard->saveData(['user' => $user], 'buyer');

$buyer = $this->wizard->stepData('buyer');
```

> [!NOTE]
> El componente 1.x exponía un único array de sesión plano, y la página leía cada valor del
> único lugar donde vivía, de modo que nada podía sobrescribir nada. `stepsData()` es esa
> garantía en el modelo 2.0. Combínelo usted mismo y nombre sus reglas explícitamente: el
> plugin no puede distinguir una sobrescritura intencionada de dos pasos que usan el mismo
> nombre para cosas distintas.

A continuación, continúe con [Control programático](programmatic-control.es.md).
