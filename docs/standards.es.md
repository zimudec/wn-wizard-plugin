# Estándares y referencias

- [Introducción](#introduction)
- [Envío doble](#double-submission)
- [Asociación de errores y resumen](#error-association-and-summary)
- [Criterios WCAG incluidos](#wcag-criteria-shipped)
- [Un compromiso, no un cumplimiento limpio](#a-trade-off-not-a-clean-pass)
- [Caché y datos personales](#cache-and-personal-data)
- [Estilizado](#styling)
- [Idempotencia](#idempotency)
- [CSRF](#csrf)

<a name="introduction"></a>
## Introducción

Las demás páginas describen lo que hace el plugin. Esta registra el estándar detrás de cada
comportamiento, de modo que una decisión de diseño pueda comprobarse en lugar de aceptarse por
confianza.

<a name="double-submission"></a>
## Envío doble

- [WHATWG HTML #5312](https://github.com/whatwg/html/issues/5312) — la plataforma web no
  previene los envíos dobles. Toda aplicación necesita su propia guarda.
- Los sistemas de diseño de
  [GOV.UK](https://design-system.service.gov.uk/patterns/questions-page-validation-errors/)
  y del [Parlamento del Reino Unido](https://designsystem.parliament.uk/button-component/)
  — no deshabilite los botones. Los controles deshabilitados no se anuncian y no explican por
  qué no pueden usarse.
- WCAG 3.3.1, y el debate de WebAIM sobre la identificación de errores (Matt King) — enviar
  repetidamente para recorrer los errores es una estrategia eficiente para algunos usuarios. Un
  botón deshabilitado se la quita.
- [MDN `aria-busy`](https://developer.mozilla.org/en-US/docs/Web/Accessibility/ARIA/Reference/Attributes/aria-busy)
  — marca la región que se está actualizando mientras hay un envío en curso.

Aplicado: el script incorporado cancela los envíos repetidos del mismo formulario en la fase de
captura, de modo que no se crea una segunda petición; el formulario lleva `aria-busy` en
curso; el botón nunca se deshabilita; y la hoja de estilos estiliza la clase `wn-loading` que
agrega `data-attach-loading`, con una alternativa para `prefers-reduced-motion`.

<a name="error-association-and-summary"></a>
## Asociación de errores y resumen

- Técnica de WAI-ARIA [ARIA1](https://www.w3.org/WAI/ARIA/apg/patterns/alert/)
  (`aria-describedby`) — vincula cada error con el control al que pertenece.
- [ARIA19](https://www.w3.org/WAI/WCAG22/Techniques/aria/ARIA19) (`role="alert"`) — anuncia un
  error apenas aparece. **La técnica la cumple el resumen de formulario, no las bolsas por
  campo.** El `FormValidation` de Snowboard elimina cada elemento `[data-validate-error]` al
  arrancar y deja un comentario marcador, reinsertando el elemento vacío solo cuando llega un
  mensaje. La bolsa por campo se agrega por tanto al DOM junto con su mensaje en lugar de estar
  presente en la carga, que no es lo que ARIA19 describe. El plugin conserva `role="alert"` en
  ella de todos modos: las bolsas se anuncian al insertarse en los lectores de pantalla que lo
  implementan, y la asociación `aria-describedby` junto con el movimiento del foco ya llegan a
  un usuario que no recibe el anuncio. El contenedor de formulario, que no tiene
  `data-validate-error`, está presente desde la carga y sí cumple la técnica.
- [Resumen de errores de GOV.UK](https://design-system.service.gov.uk/components/error-summary/)
  — los errores de formulario, es decir de reglas de negocio sin campo en el DOM, se renderizan
  en un contenedor que recibe el foco cuando no aplica ningún error de campo.

<a name="wcag-criteria-shipped"></a>
## Criterios WCAG incluidos

- [1.3.5 Identify Input Purpose](https://www.w3.org/WAI/WCAG22/Understanding/identify-input-purpose.html)
  (técnica H98) — el contrato de campo acepta un array `attributes`, de modo que
  `autocomplete` es declarable en cualquier campo que recopile datos del usuario.
- [2.2.1 Timing Adjustable](https://www.w3.org/WAI/WCAG22/Understanding/timing-adjustable.html)
  — el tiempo de inactividad es configurable por wizard y deshabilitable con `ttl = 0`.
- [3.3.3 Error Suggestion](https://www.w3.org/WAI/WCAG22/Understanding/error-suggestion.html)
  — los mensajes por paso y las sobrescrituras de idioma de la aplicación permiten que un
  mensaje indique cómo corregir el problema.
- Tutorial de formularios multipágina de WAI — el indicador de pasos sigue su estructura, y la
  posición y el nombre del paso están expuestos al layout para el título de la página.

<a name="a-trade-off-not-a-clean-pass"></a>
## Un compromiso, no un cumplimiento limpio

[3.3.7 Redundant Entry](https://www.w3.org/WAI/WCAG22/Understanding/redundant-entry.html) es de
nivel A, y el plugin no lo cumple tal como está escrito. Volver a un paso que ya envió rellena
de nuevo los valores que ingresó **en ese paso**. Lo que no hace es conservar los datos de los
pasos *posteriores*: el borrado al retroceder los elimina.

Esa es una decisión deliberada de privacidad y no un descuido, y el alcance del propio criterio
está en discusión en el grupo de trabajo, donde varios miembros sostienen que cubre retroceder y
refrescar, no solo avanzar; consulte
[w3c/wcag#4481](https://github.com/w3c/wcag/issues/4481). `retainData` hace que los pasos
posteriores sobrevivan y que los completados sean clicables, que es la configuración conforme a
cambio del valor por defecto de privacidad.

<a name="cache-and-personal-data"></a>
## Caché y datos personales

- [OWASP WSTG, debilidades de la caché del navegador](https://wstg.owasp.org/latest/4-Web_Application_Security_Testing/04-Authentication/06-Browser_Cache_Weaknesses/)
  y el [HTTP Headers Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Headers_Cheat_Sheet.html)
  — una página que muestra datos personales debe indicar al navegador que no los retenga. Las
  páginas de paso responden `Cache-Control: no-store`, de modo que los valores que escribió un
  usuario no quedan en la caché del navegador ni se recuperan con el botón de atrás.
- [Chrome, bfcache y `no-store`](https://developer.chrome.com/docs/web-platform/bfcache-ccns)
  — la directiva se aplica únicamente al render de la página, no a las respuestas de envío. Un
  XHR que también respondiera `no-store` expulsaría la página de la caché de atrás/adelante en
  cada envío, sin ganancia de privacidad alguna, porque esas respuestas no transportan datos de
  usuario. El costo del render también es casi nulo: Chrome completó en marzo y abril de 2025
  el despliegue que permite páginas `no-store` en la bfcache, el wizard navega mediante cargas
  completas de página y toca su cookie de sesión en cada petición, lo que expulsa la página de
  la bfcache de todos modos.

> [!NOTE]
> La cabecera se aplica únicamente en el ciclo de vida del render. El valor que el navegador
> acaba guardando es `no-store, private`, porque el `ResponseHeaderBag` de Symfony anexa
> `private` cuando la respuesta no tiene directiva `s-maxage` ni `public`. La que gobierna es
> `no-store`.

<a name="styling"></a>
## Estilizado

- [Capas en cascada de CSS](https://developer.mozilla.org/en-US/docs/Web/CSS/@layer) — todas
  las reglas de la hoja de estilos viven en `@layer wizard`, de modo que una hoja de estilos del
  tema sin capa siempre gana, sin `!important`.
- [Propiedades personalizadas de CSS](https://developer.mozilla.org/en-US/docs/Web/CSS/--*)
  como tokens de diseño, con el nombre `--wizard-*`.
- [`prefers-color-scheme`](https://developer.mozilla.org/en-US/docs/Web/CSS/@media/prefers-color-scheme)
  y [`prefers-reduced-motion`](https://developer.mozilla.org/en-US/docs/Web/CSS/@media/prefers-reduced-motion)
  se respetan sin configuración.

<a name="idempotency"></a>
## Idempotencia

[Claves de idempotencia de Stripe](https://docs.stripe.com/api/idempotent_requests) — ninguna
guarda del lado del cliente puede descartar un duplicado concurrente, de modo que el control del
lado del servidor le corresponde a usted. El hook `commit()` se ejecuta exactamente una vez por
envío exitoso, que es el punto en el que un duplicado se duplicaría, y un efecto secundario allí
debe llevar su propia clave de idempotencia.

<a name="csrf"></a>
## CSRF

Winter CMS [habilita la protección CSRF por defecto](https://wintercms.com/docs/v1.2/docs/services/security#csrf-protection)
y valida la vía de POST sin JavaScript. El shell del paso renderiza `{{ form_token() }}` y un
campo `_handler`, lo que la cubre. La vía AJAX de Snowboard envía `X-WINTER-REQUEST-HANDLER`
como cabecera, que un atacante de otro sitio no puede falsificar, de modo que un botón
`data-request` independiente funciona sin un token explícito.
