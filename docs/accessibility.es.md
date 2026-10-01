# Accesibilidad

- [Introducción](#introduction)
- [Lo que viene con el componente](#what-ships-with-the-component)
- [Lo que solo usted puede aportar](#what-only-you-can-provide)

<a name="introduction"></a>
## Introducción

La mayor parte del trabajo de accesibilidad de un formulario multipágina corresponde al
framework o al navegador, y el trabajo del wizard es no deshacerlo. A continuación se expone
lo que obtiene sin hacer nada y las dos cosas que el plugin no puede decidir por usted. La
razonamiento de cada comportamiento, con su cita, está en
[Estándares y referencias](standards.es.md).

<a name="what-ships-with-the-component"></a>
## Lo que viene con el componente

- **El indicador de pasos marca el paso actual** con `aria-current="step"`, y los estados
  `completed`, `current` y `pending` se transmiten mediante nombres de clase que un tema puede
  estilizar de forma distinta.
- **Los errores de campo se asocian programáticamente** con su control: el input lleva
  `aria-describedby` apuntando a la bolsa que contiene el mensaje. Ante un envío AJAX fallido,
  el script marca cada control inválido con `aria-invalid="true"` y mueve el foco al primero,
  siguiendo el
  [patrón de errores de formulario de WAI-ARIA](https://www.w3.org/WAI/ARIA/apg/patterns/alert/).
- **Los errores de regla de negocio sin campo en el DOM** aterrizan en un contenedor de
  formulario presente desde la carga de la página y que recibe el foco cuando es el único
  error, siguiendo el
  [patrón de resumen de errores de GOV.UK](https://design-system.service.gov.uk/components/error-summary/).
- **Los envíos repetidos se ignoran** mientras hay uno en curso. El segundo clic o `Enter` se
  cancela del lado del cliente antes de que Snowboard pueda iniciar una segunda petición, y el
  formulario lleva `aria-busy` mientras corre la petición. El botón de envío **nunca se
  deshabilita**, porque los controles deshabilitados no se anuncian y no explican por qué no
  pueden usarse.
- **Volver a un paso completado rellena de nuevo los valores guardados** (WCAG 3.3.7 *Redundant
  Entry*): nadie reescribe lo que el wizard ya guardó. Los datos de los pasos *posteriores* se
  borran al retroceder salvo que active `retainData`, un compromiso deliberado de privacidad
  descrito en [Retroceder](session.es.md#going-back).
- **El tiempo de inactividad es ajustable** por wizard y puede deshabilitarse con `ttl = 0`
  (WCAG 2.2.1 *Timing Adjustable*).

<a name="what-only-you-can-provide"></a>
## Lo que solo usted puede aportar

**Declare el propósito de los campos que recopilan información sobre el usuario.** WCAG 1.3.5
*Identify Input Purpose* lo pide, los navegadores actúan en consecuencia y así lo hacen
también las tecnologías de asistencia:

```php
->fields([['name' => 'buyer_email', 'label' => 'Correo electrónico', 'attributes' => ['autocomplete' => 'email']]])
```

**Escriba mensajes de error que indiquen cómo corregir el problema.** WCAG 3.3.3 *Error
Suggestion* pide una sugerencia siempre que esté disponible, y "Este campo es obligatorio" no
lo es. Consulte [Mensajes por paso](customization.es.md#per-step-messages).

**Ponga el progreso en el título de la página.** El tutorial de formularios multipágina de WAI
pide primero la posición y luego el nombre del paso; ambos valores están expuestos al layout;
consulte [Variables de vista](customization.es.md#view-variables).
