<!-- Source: docs/performance.md; SHA256: b0bdc7353e8cd2606bca23068ca61046e884463280c407a8e47d22eb43919ef1 -->

<a id="performance"></a>
# Rendimiento

Mediciones de [#47](https://github.com/somethingwithproof/plugin_cloud/issues/47), realizadas el 2026-11-15 con [`tests/bin/perf.php`](../../../../tests/bin/perf.php). CI ejecuta el mismo script en cada trabajo de Cacti y PHP. Solo hace fallar el trabajo cuando 1.000 series no terminan dentro de un intervalo del sondeador, e imprime los tiempos sin compararlos porque el hardware de los ejecutores varía.

**Resultado.** Con un servidor local de datos de prueba y sin latencia añadida, una ejecución del recolector lee 1.000 series en unos 3 segundos: aproximadamente 3 ms de trabajo del complemento y TLS por solicitud. La página de salud representa 10.000 series en caché en 0,08 segundos. Con 100 ms de latencia de proveedor simulada por solicitud, la misma ejecución tarda 106 segundos. La latencia del proveedor determina si 1.000 series caben en un intervalo del sondeador.

<a id="what-runs"></a>
## Qué se ejecuta

El script utiliza el recolector real, el controlador DigitalOcean, el transporte protegido (lista de operaciones permitidas) y el transporte por sockets, con TLS verificado mediante una CA de pruebas. Solo se reescribe el host, después de comprobar la operación, para que las solicitudes lleguen a [`tests/bin/perf-server.php`](../../../../tests/bin/perf-server.php) en vez de a `api.digitalocean.com`. Ese servidor reproduce los datos de prueba grabados, desplazando sus marcas de tiempo al momento actual, y envía las cabeceras de límite de solicitudes que lee el controlador.

| Caso | Configuración | Mide |
|---|---|---|
| Recopilación | 4 cuentas, 50 Droplets cada una, 5 series por Droplet (CPU y cuatro de ancho de banda): 1.000 series | Una ejecución del recolector en el sondeador 1 (REQ-PERF-001) |
| Presupuesto | 1 cuenta, 200 Droplets, 5 series cada uno: 1.000 series | La misma ejecución, donde el presupuesto de solicitudes detiene la cuenta |
| Página | 10 cuentas, 200 Droplets cada una, 5 series en caché por Droplet: 10.000 series | `cloud_health.php` por HTTP como administrador autenticado; mediana de cinco cargas tras un calentamiento (REQ-PERF-002) |

La recopilación usa cuatro cuentas porque un token de DigitalOcean permite 250 solicitudes por minuto. Con una cuota del 100 %, ese es el máximo que lee una cuenta en una ejecución (`RequestBudget::perRun`). El caso de presupuesto muestra el límite: la cuenta lee 250 series, marca las otras 750 como no leídas con motivo `budget`, y la página de salud lo indica.

<a id="hardware-and-settings-req-perf-003"></a>
## Hardware y ajustes (REQ-PERF-003)

| | |
|---|---|
| Equipo anfitrión | Apple M3 Max, 14 núcleos, 36 GiB |
| Entorno de ejecución | VM Linux de OrbStack, aarch64, 14 CPU, 15,7 GiB, imagen Docker `cloud-ci:8.2` |
| PHP | 8.2.35, CLI para el recolector, servidor web integrado para la página |
| Base de datos | MariaDB 11.8.9, en la misma VM |
| Cacti | 1.2.31 y la rama 1.2.x (1.2.32) |
| Intervalo del sondeador | 300 segundos |
| Cuentas | Intervalo de recopilación 300 segundos, cuota de solicitudes 100 %, descubrimiento no pendiente durante la ejecución |
| Presupuesto temporal del recolector | Intervalo del sondeador menos 10 segundos (290); el ajuste predeterminado del complemento es 240 |

<a id="results"></a>
## Resultados

Tres ejecuciones en cada versión de Cacti, sin latencia añadida:

| Caso | Cacti 1.2.31 | Cacti 1.2.x |
|---|---|---|
| Recopilación, 1.000 series en 4 cuentas | 2,83, 3,00, 3,17 s; 1.000 leídas con 1.000 solicitudes | 3,42, 2,59, 2,61 s; 1.000 leídas con 1.000 solicitudes |
| Presupuesto, 1.000 series en 1 cuenta | 1,20, 1,32, 1,10 s; 250 leídas, 750 pendientes con motivo `budget` | 1,24, 1,72, 1,10 s; 250 leídas, 750 pendientes con motivo `budget` |
| Página de salud, 10.000 series | Mediana 0,08 s, máximo 0,08 s, en las tres ejecuciones | Mediana 0,08 s, máximo de hasta 0,09 s |

Con `perf.php <url> 100`, que añade 100 ms antes de cada respuesta de prueba, el caso de recopilación tardó 106,26 segundos en Cacti 1.2.31 y leyó las 1.000 series. El caso de presupuesto tardó 27,02 segundos.

Se comprobó el contenido de la página, además de su velocidad. Cada una de las 10 cuentas mostraba 1.000 series actuales y una estimación de 12.000 solicitudes por hora (1.000 series cada 300 segundos).

<a id="reading-the-results"></a>
## Interpretación de los resultados

- **El tiempo de ejecución crece con la latencia del proveedor.** El recolector lee una serie por solicitud, de una en una. Una ejecución tarda aproximadamente solicitudes × (latencia + 3 ms). Con el presupuesto predeterminado de 240 segundos, caben 1.000 series mientras el viaje de ida y vuelta medio esté por debajo de unos 237 ms. Por encima, el recolector deja de iniciar cuentas nuevas al agotar el presupuesto y las cuentas no leídas se recopilan en la siguiente ejecución. La primera [comprobación real](../../../research/provider-facts.md#live-checks) (#16) midió una mediana de 384 ms (de 301 a 799 ms) desde una conexión doméstica. Con esa latencia, 1.000 series tardan unos 390 segundos, por lo que el presupuesto predeterminado lee unas 620 por ejecución y aplaza el resto. Un sondeador en un centro de datos cercano a DigitalOcean debería tener menos latencia; mida desde su propio sondeador antes de planificar más de unas 600 series en uno solo.
- **El límite de DigitalOcean es por token.** Con la cuota predeterminada del 50 %, una cuenta lee 125 series por ejecución. Los entornos mayores necesitan más cuentas, cada una con su token, y la estimación de la página de salud indica lo cerca que está cada cuenta de su cuota.
- **La página mantiene un coste bajo con 10.000 series.** Solo lee filas en caché y nunca llama a un proveedor, por lo que su coste crece con las tablas del complemento, no con la latencia del proveedor.
- **No se han medido aquí:** actualizaciones RRD del sondeador de Cacti, boost, Spine, recolectores remotos y una base de datos en otro equipo. Cada uno añade costes externos al complemento.

<a id="running-it"></a>
## Ejecución

En una base de datos desechable, con el complemento instalado y activado y Cacti servido en una URL:

```sh
CLOUD_TEST_DATABASE=disposable php plugins/cloud/tests/bin/perf.php http://127.0.0.1:8080/cacti/
CLOUD_TEST_DATABASE=disposable php plugins/cloud/tests/bin/perf.php http://127.0.0.1:8080/cacti/ 100
```

El segundo argumento añade ese número de milisegundos de latencia a cada respuesta de prueba. El script elimina todas las cuentas, recursos, series y observaciones del complemento y restablece la contraseña del administrador; nunca lo ejecute contra una base de datos que desee conservar.
