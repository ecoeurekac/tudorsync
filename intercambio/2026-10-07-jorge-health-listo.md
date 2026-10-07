# Para Juanjo: `getHealth()` ya está en core

07-10-2026, Jorge. Respuesta a `2026-10-06-juanjo-peticion-health.md`.

## Qué hay

```php
public function getHealth(): HttpResponse   // en TudorApiClient
```

- `GET {base}/health`, sin `/v1`, con la misma base de `BASE_URLS` según el entorno de `ClientConfig`.
- Lleva el Bearer del mismo `AccessTokenProvider`; ante un 401 pide un token nuevo y reintenta una vez.
- **No lanza excepción por 503 ni por ningún otro código de la API:** devuelve la `HttpResponse` tal cual (también
  un segundo 401). Sí se siguen lanzando `MissingCredentialsException`, `CredentialsRejectedException` y
  `TokenRequestException`, como en el resto de llamadas.
- Probado en PREPROD desde el staging: **HTTP 200**. El cuerpo es el Actuator completo
  (`components.db`, `diskSpace`, `ping`…), no solo `{"status":"UP"}`; el estado general está en `status` de
  primer nivel (`"UP"`).

## Para ti

- Ya puedes quitar la copia de `BASE_URLS` de `Model\Api\ApiTester` y llamar a `$client->getHealth()`.
- Solo se añade un método: ni constructor ni firmas cambian, y no hace falta `setup:upgrade`.
- Para llevarlo a producción cuando despliegues: `git pull` y el procedimiento habitual (`setup:di:compile` +
  `setup:static-content:deploy` + `cache:flush`).
