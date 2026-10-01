# Privacidad

- [Introducción](#introduction)
- [Qué hace el wizard con los datos](#what-the-wizard-does-with-the-data)
    - [Tiempo de inactividad deslizante](#sliding-inactivity-timeout)
    - [Borrado al retroceder](#wipe-on-back)
    - [Borrado al finalizar](#cleared-on-finish)
    - [Cifrado opcional](#opt-in-encryption)
    - [Persistencia con lista blanca](#whitelisted-persistence)
    - [No retenido por el navegador](#not-retained-by-the-browser)
- [Lista de verificación de privacidad](#privacy-checklist)

<a name="introduction"></a>
## Introducción

El wizard recopila datos personales y los mantiene en la sesión entre peticiones, de modo que
lo que hace con ellos forma parte de su contrato y no es un detalle de implementación. Esta
página enuncia ese contrato. Para el mecanismo detrás de cada punto, consulte
[Sesión](session.es.md#what-persists).

Todos los puntos son configurables, y los valores por defecto son la opción conservadora.

<a name="what-the-wizard-does-with-the-data"></a>
## Qué hace el wizard con los datos

<a name="sliding-inactivity-timeout"></a>
### Tiempo de inactividad deslizante

El estado expira tras 30 minutos sin actividad, medido del lado del servidor y refrescado en
cada render y en cada envío. Cuando expira, la siguiente interacción lo elimina y reinicia el
flujo.

Configúrelo por wizard con la propiedad `ttl` o `->ttl()`, o para toda la aplicación en
[Valores por defecto globales](installation.es.md#global-defaults). `0` lo deshabilita;
dejarlo sin declarar toma el valor global. Deshabilitarlo no se recomienda; consulte
[2.2.1 Timing Adjustable](standards.es.md#wcag-criteria-shipped) para el requisito de
accesibilidad al que responde.

<a name="wipe-on-back"></a>
### Borrado al retroceder

Volver a un paso anterior borra los datos de todos los pasos posteriores. Los pasos completados
del indicador no son clicables, de modo que no se invita al usuario a provocarlo sin querer.

`keepSteps` nombra los pasos cuyos datos sobreviven y borra todos los demás pasos posteriores.
`retainData` es el compromiso opuesto: conserva todos los pasos posteriores y vuelve clicables
los pasos completados. Consulte [Retroceder](session.es.md#going-back).

<a name="cleared-on-finish"></a>
### Borrado al finalizar

Enviar el último paso elimina todo el estado al final de la petición. Los datos están
disponibles para esa petición y no para la siguiente, de modo que recargar la última página
reinicia el wizard.

Un paso que declara `->keepData()`, o un wizard con `retainData` activo, aplaza el borrado
hasta el siguiente reinicio del flujo. Consulte [Borrado al finalizar](session.es.md#cleared-on-finish).

<a name="opt-in-encryption"></a>
### Cifrado opcional

Los datos persistidos pueden cifrarse con la clave de la aplicación. Sin ello, el wizard usa el
almacenamiento de sesión habitual del CMS, y lo que los protege es lo que proteja la sesión.

La lectura tolera datos escritos antes de activar el cifrado y datos escritos con un
`APP_KEY` anterior: un valor indescifrable se devuelve tal como está almacenado, el formulario
muestra ruido en lugar de que la petición falle y el siguiente envío del usuario lo sobrescribe.
Sin caída y sin fuga.

<a name="whitelisted-persistence"></a>
### Persistencia con lista blanca

Un paso persiste solo lo que declaró: las claves de sus reglas, incluida la notación de
comodines de Laravel, y los nombres de sus campos declarados. Todos los demás campos de la
petición se descartan, y también todos los archivos subidos: los archivos nunca se serializan
en la sesión.

Un campo declarado sin regla se persiste y nunca se valida. Una regla sobre una clave padre
cubre todo lo que hay por debajo **solo mientras no haya ninguna regla declarada dentro**;
declare además `items.*.quantity` y las reglas de abajo definen lo que se guarda, de modo que
un `items.0.admin` manipulado se descarta como cualquier otra clave no declarada.

<a name="not-retained-by-the-browser"></a>
### No retenido por el navegador

La página de un paso vuelve a rellenar los valores que el usuario ya escribió, de modo que
transporta datos personales en su respuesta. Por eso responde con
`Cache-Control: no-store`: el navegador no guarda el HTML en su caché y el botón de atrás no
trae los datos de vuelta.

Las respuestas de envío no transportan datos de usuario y no se marcan. Consulte
[Caché y datos personales](standards.es.md#cache-and-personal-data) para saber por qué,
incluido el compromiso con la caché de atrás/adelante.

<a name="privacy-checklist"></a>
## Lista de verificación de privacidad

Antes de publicar un wizard que recopila datos personales, confirme tres decisiones:

1. El **tiempo de inactividad** se ajusta a la sensibilidad de los datos. El valor por defecto
   son 30 minutos.
2. **`wipeOnBack`** (el valor por defecto) o **`retainData`** coincide con la promesa que hace
   al usuario sobre qué se conserva cuando retrocede.
3. **`encrypted`** está activo cuando el almacenamiento de sesión ya no está cifrado.

A continuación, consulte [Accesibilidad](accessibility.es.md).
