# VUMC Picture backend & API setup

## Table of content
- [Prerequisites](#prerequisites)
- [Installation](#installation)
    - [Docker and Docker Compose](#docker-and-docker-compose)
    - [Initial Setup](#initial-setup)
- [Configuration](#configuration)
    - [SSL Configuration](#ssl-configuration)
    - [Database Setup](#database-setup)
    - [File and Storage Configuration](#file-and-storage-configuration)
- [Services Management](#services-management)
    - [Building and Running Containers](#building-and-running-containers)
    - [Stopping Services](#stopping-services)
- [Technical Specifications](#technical-specifications)
    - [System Architecture](#system-architecture)
    - [Containerization and Orchestration](#containerization-and-orchestration)
    - [Core Technologies](#core-technologies)
    - [Data Management](#data-management)
    - [Networking and Security](#networking-and-security)
    - [Environmental Configuration](#environmental-configuration)
    - [Development and Maintenance Tools](#development-and-maintenance-tools)
    - [Operational Scripts](#operational-scripts)
- [Additional Configurations](#additional-configurations)
    - [Laravel Setup](#laravel-setup)
    - [Mail Services](#mail-services)

## Prerequisites
Before setting up the VUMC Picture API, ensure that Docker and Docker Compose are installed on the system. These are typically installed via Ansible automation scripts.

## Installation

### Docker and docker compose
Docker and Docker Compose are essential for running the services. Though typically installed via Ansible, relevant commands are commented in this section for reference:
```bash
# Install Docker
# Follow the commented steps if Docker is not already installed by Ansible
# Commands to install Docker are provided here for archival purposes

# Install Docker Compose
# sudo curl -L "https://github.com/docker/compose/releases/download/1.21.2/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
# sudo chmod +x /usr/local/bin/docker-compose
```

### Initial setup
Create a symbolic link to the volume containing project data for easier access:
```bash
sudo ln -s /data/volume_1/picture/ /srv/projects
```

## Configuration

### SSL configuration
Generate and configure SSL certificate for secure communications:
```bash
sudo docker exec -it reverse-proxy_nginx_1 certbot --nginx -d tool.picture.project.nl
# MANUAL ACTION: Uncomment SSL lines in nginx.conf after obtaining the certificate
sudo docker-compose restart
```

### Database setup
If deploying anew, import the database using the following command:
```bash
sudo docker cp ../picture.sql vumc-picture-api_db_1:/picture.sql
sudo docker exec -it vumc-picture-api_db_1 mysql -u picture -p picture < /picture.sql
```

### File and storage configuration
Set proper permissions and link storage:
```bash
sudo chown -R www-data:www-data laravel-storage
sudo chmod -R 775 laravel-storage
sudo docker exec -it vumc-picture-api_api_1 php artisan storage:link
sudo mkdir -p laravel-storage/app/public/{l,h,nifti}
sudo mkdir -p laravel-storage/dicom-unprocessed
```

## Services management

### Building and running containers
Navigate to project directories and build/start services:
```bash
cd /srv/projects/
sudo docker-compose up -d
cd /srv/projects/vumc-picture-api
sudo docker-compose build
sudo docker network create proxy
sudo docker-compose up -d
```

### Stopping services
To stop services, particularly before updating configurations:
```bash
sudo service nginx stop
cd /srv/projects/reverse-proxy
sudo docker-compose up -d
```

## Technical specifications

### System architecture
The VUMC Picture API employs a microservices architecture to enhance scalability, maintainability, and resilience. This architecture supports modular development and deployment of services, allowing for the independent scaling and versioning of distinct components.

### Containerization and orchestration
- **Docker**: Utilized for isolating services in lightweight, portable containers. Docker ensures that each microservice can operate independently while interacting efficiently through a well-defined network.
- **Docker Compose**: Manages the orchestration of multi-container setups specified in `docker-compose.yml`. This orchestration includes network creation, inter-container dependencies, and service coordination.

### Core technologies
- **Python 3**: The primary language for backend services, selected for readability, and a vast array of supported libraries.
- **Flask**: This micro web framework handles API request routing, offering a straightforward mechanism for building RESTful interfaces.
- **Celery with Redis**: Facilitates asynchronous job handling. Redis acts as a message broker, queuing up tasks triggered by API calls, which Celery workers process.
- **Gunicorn**: Serves as a WSGI server for Flask applications, mediating requests between Flask and the web.

### Data management
- **MySQL**: Serves as the primary relational database management system, crucial for persistent storage and retrieval of structured data used across services.
- **Volume Management**: Docker volumes are designated for sustained data storage across container restarts. Essential directories such as `/mnt/data/nnUnet-data`, `/mnt/data/nnUNet_preprocessed`, and `/mnt/data/nnUNet_trained_models` are mapped as volumes.

### Networking and security
- **Nginx Reverse Proxy**: Configured to route requests to appropriate backend services securely and efficiently. It also manages SSL termination for encrypted client-server communication.
- **SSL Certificates**: Managed with Certbot, enhancing security by encrypting the API traffic which ensures confidentiality and integrity.

### Environmental configuration
Configurations are managed via environment variables that specify paths and operational parameters without modifying the codebase:
- `LC_ALL` and `LANG` settings ensure appropriate locale handling, important for diverse data handling.
- Dependency management is streamlined through `requirements.txt`, securing consistency across installations.

### Development and maintenance tools
- **Git**: Utilized for version control, facilitating effective team collaboration and code management.
- **Ansible**: Automates the provisioning, configuration, deployment, and management of the server and its services, improving consistency and deployment speed.

### Operational scripts
- **Entrypoint Scripts (`entrypoint.sh`)**: Script initiates services according to the configurations set in `supervisord.conf`. It is crucial for service bootstrapping and initial health checks.
- **Supervisord**: Manages process lifecycles, ensuring that services such as Celery workers are continuously operational and restart in case of failures.

### Additional technical details
- **NVIDIA Docker Runtime**: Leveraged for GPU-accelerated tasks, optimizing processes like image segmentation.
- **Python Python Libraries**: Critical libraries such as `hiddenlayer`, `batchgenerators`, and `nnunet-master` are incorporated, enhancing the system’s capabilities in machine learning and image processing.

### Storage and permissions
Detailed configuration steps to set up proper storage and file permissions ensures robust data integrity and access control. This includes setting up user permissions and linking storage directories within the Laravel framework.

## Additional configurations

### Laravel setup
Generate key for Laravel and update dependencies:
```bash
sudo docker exec -it vumc-picture-api_api_1 php artisan key:generate
sudo docker exec -it vumc-picture-api_api_1 composer update
sudo docker exec -it vumc-picture-api_api_1 php artisan passport:install
sudo docker exec -it vumc-picture-api_api_1 php artisan passport:client --personal
```

### Mail Services
Install and configure email services (if necessary):
```bash
sudo apt install postfix dovecot-core dovecot-pop3d dovecot-imapd s-nail
```
