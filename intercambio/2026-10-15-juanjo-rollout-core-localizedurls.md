---
de: Juanjo
para: Jorge + los responsables de cada despliegue (Quera/PLO Magento, Grau PrestaShop, Gordillo/Saphir WooCommerce)
tema: Actualizar el core (fix localizedUrls, 648d5a4) en TODOS los distribuidores — el core debe ser el mismo en todos
fecha: 2026-10-15
---

# Rollout del fix de core `localizedUrls` (648d5a4) a todos los distribuidores

## Qué ha cambiado
`core` tiene en `main` el commit **648d5a4** (Jorge): `TudorApiClient::toStockCreatePayload` manda
`localizedUrls` como **objeto JSON** (`(object) $item->localizedUrls` → `{}` cuando está vacío),
en vez de un array PHP vacío que se serializaba `[]`. Sin esto, `POST /v1/stocks(/batch)` da
**HTTP 400 "Failed to read HTTP message"** cuando una ficha no lleva URLs por idioma (reproducido
en PREPROD de Saphir; ver `intercambio/2026-10-15-juanjo-batch-localizedurls-array-vacio.md` y la
nota de Jorge `2026-10-15-jorge-localizedurls.md`). 105 tests de core en verde.

## Principio: el core tiene que ser IGUAL en todas las tiendas
El despliegue es híbrido: cada módulo lleva su propia copia de `tudorsync/core` (dependencia
Composer). Para que las 5 tiendas se comporten igual, **todas deben correr la misma versión de
core**. Por tanto este fix hay que propagarlo a TODOS los distribuidores, no solo a Saphir — aunque
hoy solo WooCommerce lo disparaba.

## A quién le corre prisa (y a quién no)
- **Magento (Quera, PLO)** y **PrestaShop (Grau)**: NO estaban rotos — siempre mandan `localizedUrls`
  no vacío (multi-store / multi-idioma), así que nunca veían el 400. El fix es robustez.
- **WooCommerce (Gordillo, Saphir)**: era donde `localizedUrls` iba vacío. El plugin unificado
  (v0.3.x) **ya lo esquiva** mandando `{"<idioma del sitio>": URL}`, así que tampoco están rotos
  ahora mismo; el fix de core es el cinturón de seguridad definitivo y compatible con ese workaround.

Conclusión: **ninguna tienda está caída**, pero el core debe quedar igual en todas → actualizar en
el **próximo despliegue** de cada una, no hace falta emergencia.

## Cómo actualizar en cada tienda
En cada instalación, traer el nuevo core y regenerar el autoload de su módulo:

1. `git pull --ff-only` del repo (o del checkout que tenga la tienda) hasta incluir 648d5a4.
2. Regenerar `vendor` del módulo con **el mismo PHP de la tienda (8.1+)**, desde la ruta real del
   módulo (no por el symlink del plugin):
   - WooCommerce: `cd modules/woocommerce && composer install` (o `composer update tudorsync/core`).
   - PrestaShop: `cd modules/prestashop && composer install`.
   - Magento: actualizar la dependencia de core y `bin/magento setup:upgrade` + `setup:di:compile`
     (modo producción) según el procedimiento de Quera.
3. Smoke test mínimo tras actualizar: `catalog preview` (o equivalente) y un envío a **PREPROD**
   antes de tocar PROD.

## Pendiente de decidir (afecta a lo cómodo del rollout)
Cómo tira cada tienda de `tudorsync/core` en producción sigue sin cerrarse (path repo local,
Satis, Packagist privado, o core “horneado” en el artefacto de despliegue). Mientras sea un
checkout con path repo (como el staging de Saphir), basta `git pull` + `composer install`. Si se
monta un registro, habría que publicar la versión de core y fijar la misma en los 5 `composer.json`.
**Sería bueno cerrar esto para que “core igual en todas” sea automático y no manual.**

## Estado por tienda (ir marcando)
- [ ] Saphir (WooCommerce) — staging ya en 648d5a4 (checkout). Falta producción.
- [ ] Gordillo (WooCommerce)
- [ ] Grau (PrestaShop)
- [ ] Quera (Magento)
- [x] PLO (Magento) — 15-10: `git pull` (core por symlink del path repo, modo default: sin setup:upgrade ni di:compile). Preview y `sync:run` a PREPROD: 30 enviados, 0 fallos.
