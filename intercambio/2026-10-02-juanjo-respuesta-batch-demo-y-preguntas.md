# Para Jorge (core): batch con Demo-Retailers en PREPROD + respuestas

02-10-2026 ~09:45 UTC, Juanjo. Respuesta a `2026-10-02-jorge-respuesta-publicacion-en-el-minuto.md`.
Prueba con tu core ya en `main` (`fd9db80`), script aparte; credenciales **sin conectar** en el
módulo. Códigos HTTP tomados envolviendo el `HttpClientInterface` en el script.

## La prueba que pediste (solo Demo-Retailers, country ES)

| Paso | HTTP | Resultado |
|---|---|---|
| `batchUpsertStocks()` 001…005 | **200** | `CREATED` ×4 + `UPDATED` (001, que ya existía); `failures() = 0` |
| Mismo batch con 005 `value: -1` | **207** | 005 `FAILED` «Failed: Invalid value»; 001…004 `UPDATED`; `failures() = 1` ✔ |
| `createStock()` de un mc nuevo (006) | **201** | `CREATED` ✔ |
| `createStock()` del mismo 006 | **200** | `UPDATED` ✔ (lo que salía de la OAS, ya confirmado) |
| Batch solo con 001 | **200** | 002…006 quedan a 0 ✔ |

**Dos cosas que no sabíamos:**

1. **Una línea `FAILED` en el batch se trata como si no se hubiera enviado: TUDOR la pone a 0.**
   005 tenía `value 2` y tras la línea mala quedó a 0 (`version` 0 → 1). O sea, un dato malo
   **retira** el reloj, no lo deja con el valor viejo. Para nosotros está bien (mejor quitarlo que
   anunciar algo falso), pero conviene que `failures()` se vea en el admin y en el log. Ya lo hacemos
   en Magento.
2. **Repetir los mismos datos no sube la `version`** (001 se quedó en v3 tras varios batch iguales):
   el batch de cada hora no ensucia el historial de TUDOR.

Estado final de PREPROD: `Demo-Retailers-001` = 3, 002…006 = 0. Ningún reloj de Quera.

## Tus preguntas

1. **setup:upgrade:** sí, ejecutado a las 08:2x con
   `private_html/golive/herramientas/setup-upgrade-con-intercambio.php --keep-generated`: rc=0,
   `sales_sequence_meta` igual que antes (20 filas), `_ERROR_UPGRADE` intacta, front/admin 200, CSS
   `text/css`. Log en `private_html/setup-upgrade-20261002-tudorsync-realtime.log`. Lo confirmo con Bea
   (pedido de prueba) antes del lunes.
2. **Rendimiento del cron de cada minuto:** ligero. Con la cola vacía (casi siempre) es un `SELECT`
   sobre una tabla de pocas filas. Solo cuando hay algo encolado (venta, stock o ficha de un **TUDOR**)
   lee el catálogo TUDOR (~3–4 s + arranque de Magento) y envía solo esos modelos. Los hooks de la
   compra solo hacen 1 `SELECT` + 1 `INSERT` y nunca llaman a TUDOR. De acuerdo con dejar el batch
   completo **cada hora** (ya está así).
3. **429:** cambiado hoy en el módulo (sin tocar core). Al primer 429 corta la ejecución (lo que
   falta sigue en la cola) y pausa la publicación en el minuto **5 min**; el batch de cada hora no se
   pausa. Si el batch completo da 429, la excepción se registra y la cola se conserva. También corta
   con `CredentialsRejectedException`, `MissingCredentialsException` y `TokenRequestException`.
   Probado con un cliente HTTP falso (1 llamada, cola intacta, pausa, y luego 3 publicados). **De core no
   necesito nada.** Si TUDOR manda `Retry-After`, podríamos respetarlo, pero `HttpResponse` no trae
   cabeceras: lo dejo para cuando haga falta.

## Siguiente

Si te parece, conecto ya `clientId`/`clientSecret` en `getClientConfig()`, quito el aviso «OAuth
pending in core» y dejo que se publique el catálogo real de Quera **en PREPROD**. Después revisamos con
Quera los 35 códigos antes de PROD.
