# 光 HikariBeam: Purge & Preload Nginx MicroCache Warmer

Motor ultra-ligero de **purga quirúrgica en RAM (`/dev/shm`)**, **purga selectiva en Cloudflare Edge API** y **precalentamiento asíncrono no bloqueante (Cache Warmer)** para WordPress y Nginx FastCGI Microcache.

Diseñado con ingeniería de precisión para infraestructuras de recursos estrictos (como instancias Google Cloud `e2-micro` de 1 GB RAM con discos persistentes limitados a 45 IOPS).

---

## 👥 Créditos y Autoría

* **Ingeniería y Desarrollo:** **Antigravity (AGY)** (Google DeepMind Team Agent).
* **Ideación Original, Arquitectura y Usuario Cero:** [Samuel Suiri](https://github.com/SamuelSuiri) — Fundador de [MundoSuiri](https://github.com/MundoSuiri) y editor de [samsuiri.qd.je](https://samsuiri.qd.je).

---

## 🔍 Análisis Comparativo: Soluciones Existentes vs Suiri Fullstack Cache

Al analizar las herramientas más populares en GitHub para la gestión de caché Nginx en WordPress, encontramos que cada una posee ventajas notables pero incurre en riesgos críticos al ejecutarse en servidores con restricciones estrictas de hardware:

| Proyecto / Enfoque | Lógica Operativa | Beneficios para WordPress | Puntos de Fallo en VPS / Nube (1 GB RAM / IOPS Limitados) | Solución en Suiri Fullstack Cache |
| :--- | :--- | :--- | :--- | :--- |
| **`psaux-it/nginx-fastcgi-cache-purge-and-preload` (NPP)** | Dispara subprocesos del sistema operativo (`wget` o crawlers externos guiados por `.pid`). | Desacopla la tarea de la petición HTTP del usuario en WP-Admin. Soporta WP-CLI. | **Riesgo crítico de memoria:** Invocar procesos binarios externos concurrentes compite directamente por RAM con MariaDB y PHP-FPM, disparando el OOM Killer en picos de publicación. | **Zero Subprocesos:** Usa el transporte HTTP asíncrono nativo de WordPress (`wp_remote_get` con `'blocking' => false`), sin invocar binarios externos ni consumir memoria adicional. |
| **`rtCamp/nginx-helper`** | Hook nativo de WordPress en `save_post` que envía peticiones HTTP al frontend. | Integración nativa sin dependencias del sistema operativo; configuración modular. | **Bloqueo de UI:** Si la llamada es síncrona o con timeout estándar, el autor experimenta latencia y rueda de espera en el botón "Actualizar post" mientras PHP se procesa a sí mismo. | **Ejecución No Bloqueante:** Timeout estricto de 0.5s y despacho asíncrono inmediato; la interfaz de administración responde en milisegundos. |
| **`rsmith4321/nginx-cache-warmer`** | Script Bash cronómetro que recorre secuencialmente el `/sitemap.xml` con pausas (`sleep 1`). | Cobertura total de URLs y taxonomías antiguas. | **Desperdicio de CPU y Disco:** En discos de nube con cuota baja de IOPS (45 IOPS), recorrer cientos de URLs periódicamente degrada la base de datos para calentar posts que no reciben visitas. | **Calentamiento Quirúrgico:** Solo calienta las URLs que acaban de cambiar (el post modificado, la portada `/` y el feed `/feed/`). |

---

## 🚀 Características Clave

1. **Purga Físicamente Quirúrgica en RAM:**
   - Calcula el hash MD5 de la directiva de clave Nginx:  
     $$\text{key} = \text{scheme} + \text{method} + \text{host} + \text{request\_uri}$$
   - Resuelve la topología `levels=1:2` y borra con `@unlink()` exactamente el archivo de caché en `/dev/shm/nginx-cache/`, sin vaciar toda la caché ni causar degradación global.
2. **Sincronización con Cloudflare Edge (CDN):**
   - Dispara una llamada a la API de Cloudflare (`/zones/{zone_id}/purge_cache`) enviando únicamente las URLs afectadas (`files: [...]`).
   - Evita el clásico problema de "sitio desactualizado en CDN aunque esté actualizado en el servidor".
3. **Precalentamiento Automático (Warm-up):**
   - Emite una petición HTTP anónima (sin cookies de sesión de WordPress).
   - Fuerza a Nginx a generar y almacenar el nuevo HTML en la memoria RAM compartida al instante.
   - El primer visitante humano recibe un **Cache HIT (0ms de tiempo de backend)**.
4. **Protección de Credenciales (Security First):**
   - No expone claves maestras ni tokens de IA. Utiliza variables dedicadas `CLOUDFLARE_PURGE_API_TOKEN` o constantes seguras de entorno.

---

## 🛠️ Instalación y Configuración

### Modo `mu-plugin` (Recomendado)
Copia el archivo `suiri-fullstack-cache.php` directamente a la carpeta de plugins indispensables:
```bash
cp suiri-fullstack-cache.php /var/www/html/wp-content/mu-plugins/
```

### Configuración en `wp-config.php` (Opcional para Cloudflare)
Para activar la purga en los servidores de borde de Cloudflare:
```php
define( 'CLOUDFLARE_ZONE_ID', 'tu_zone_id_de_32_caracteres' );
define( 'CLOUDFLARE_PURGE_API_TOKEN', 'tu_token_con_permiso_Cache_Purge' );
```

---

## 📄 Licencia

Distribuido bajo la licencia [GPL-2.0-or-later](LICENSE).  
Desarrollado con orgullo para el ecosistema abierto de [MundoSuiri](https://github.com/MundoSuiri).

