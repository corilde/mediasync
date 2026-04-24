# MediaSync — Docker

Interfície web per transferir pel·lícules i sèries des de Radarr/Sonarr entre servidors via rsync.

## Estructura de fitxers

```
mediasync/
├── Dockerfile
├── docker-compose.yml
├── entrypoint.sh
├── api.php
└── index.html
```

## Posada en marxa ràpida

### 1. Edita docker-compose.yml

Omple les variables d'entorn:

```yaml
environment:
  RADARR_URL: "http://192.168.1.x:7878"
  RADARR_API_KEY: "la_teva_clau"
  SONARR_URL: "http://192.168.1.x:8989"
  SONARR_API_KEY: "la_teva_clau"
  SOURCE_USER: "user"
  SOURCE_HOST: "192.168.1.100"
  SOURCE_BASE_PATH: "/media"
  DEST_BASE_PATH: "/downloads"
```

### 2. Clau SSH (recomanat)

```bash
# Generar clau si no en tens
ssh-keygen -t rsa -b 4096 -f ~/.ssh/id_rsa -N ""

# Copiar al servidor origen
ssh-copy-id user@192.168.1.100
```

### 3. Arrencar

```bash
docker compose up -d --build
```

### 4. Accedir

http://localhost:8080

---

## Variables d'entorn

| Variable           | Descripció                              |
|--------------------|-----------------------------------------|
| RADARR_URL         | URL de Radarr                           |
| RADARR_API_KEY     | API Key de Radarr                       |
| SONARR_URL         | URL de Sonarr                           |
| SONARR_API_KEY     | API Key de Sonarr                       |
| SOURCE_USER        | Usuari SSH servidor origen              |
| SOURCE_HOST        | IP/host servidor origen                 |
| SOURCE_BASE_PATH   | Ruta base al servidor origen            |
| DEST_USER          | Usuari SSH servidor desti               |
| DEST_HOST          | IP/host servidor desti (buit = local)   |
| DEST_BASE_PATH     | Ruta base al desti                      |
| RSYNC_EXTRA        | Opcions extra rsync                     |
| SSH_KEY            | Ruta clau SSH dins el contenidor        |

## Muntar carpeta de desti local

```yaml
volumes:
  - /ruta/local/downloads:/downloads
```
