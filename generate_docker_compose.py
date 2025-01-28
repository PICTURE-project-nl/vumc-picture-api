import os
import subprocess
import sys

# Debug logging function
def log_debug(message):
    print(f"DEBUG: {message}")

# Check for NETWORK_PREFIX or set default
network_prefix = os.getenv('NETWORK_PREFIX', 'default_prefix')
log_debug(f"Network prefix set to: {network_prefix}")

# Function to check if NVIDIA GPU is available
def is_gpu_available():
    try:
        # Attempt to run the nvidia-smi command
        result = subprocess.run(["nvidia-smi"], check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        log_debug("NVIDIA GPU detected.")
        return True
    except FileNotFoundError:
        log_debug("nvidia-smi command not found; assuming no GPU available.")
        return False
    except subprocess.CalledProcessError as e:
        log_debug(f"nvidia-smi command failed with error: {e}; assuming no GPU available.")
        return False
    except Exception as e:
        log_debug(f"Unexpected error while checking for GPU: {e}; defaulting to CPU mode.")
        return False

# Set GPU availability based on detection
gpu_available = '1' if is_gpu_available() else '0'
log_debug(f"GPU available: {gpu_available} (1 for yes, 0 for no)")

# Define the GPU deploy section if a GPU is detected
gpu_deploy_section = f"""
  gsi-rads:
    platform: linux/amd64
    build:
      context: ./gsi-rads
      dockerfile: Dockerfile_prebuilt_tensorflow
      args:
        TARGET_ARCH: ${'TARGET_ARCH'}
    volumes:
      - gsi_rads_output:/gsi_rads/out
    links:
      - redis
    networks:
      - internal
    restart: always
    environment:
      - GPU_AVAILABLE={gpu_available}
      - TARGET_ARCH=arm64
""" if gpu_available == '1' else """
  gsi-rads:
    platform: linux/amd64
    build:
      context: ./gsi-rads
      dockerfile: Dockerfile_prebuilt_tensorflow
      args:
        TARGET_ARCH: ${'TARGET_ARCH'}
    volumes:
      - gsi_rads_output:/gsi_rads/out
    links:
      - redis
    networks:
      - internal
    restart: always
"""

# Docker Compose template for vumc-picture-api
docker_compose_template = f"""
version: '3'

services:
  db:
    image: mysql:8.0
    platform: linux/amd64
    env_file:
      - secrets.env
    networks:
      - internal
    volumes:
      - ./db_test_data:/docker-entrypoint-initdb.d/
      - mysql_data:/var/lib/mysql
    restart: always
  phpmyadmin:
    image: phpmyadmin/phpmyadmin
    platform: linux/amd64
    container_name: phpmyadmin_container
    depends_on:
      - db
    networks:
      - internal
    environment:
      - PMA_HOST=db
      - PMA_USER=${{MYSQL_USER}}
      - PMA_PORT=3306
      - PMA_PASSWORD=${{MYSQL_PASSWORD}}
    ports:
      - 9090:80
    restart: always
  redis:
    image: bitnami/redis:latest
    platform: linux/amd64
    networks:
      - internal
    environment:
      - ALLOW_EMPTY_PASSWORD=yes
    restart: always
  api:
    build: ./laravel
    platform: linux/amd64
    volumes:
      - ./laravel/src:/var/www/laravel
      - ./laravel-storage:/var/www/laravel/vumc-picture-api/storage
    links:
      - db
      - redis
      - registration
      - segmentation
      - gsi-rads
    env_file:
      - common.env
      - secrets.env
    environment:
      - APP_ENV=local
      - DB_HOST=db
      - DB_CONNECTION=mysql
      - SERVER_NAME=localhost
      - SERVER_HOSTNAME=localhost
    networks:
      - internal
      - proxy
      - filtering
    restart: always
  registration:
    build: ./registration
    volumes:
      - registered_nii_data:/wdir/out
    links:
      - redis
    networks:
      - internal
    restart: always
  segmentation:
    build: ./segmentation
    shm_size: 1gb
    networks:
      - internal
    restart: always
{gpu_deploy_section}

networks:
  proxy:
    external: true
    name: "{network_prefix}_proxy"
  internal:
    external: false
  filtering:
    external: true
    name: "{network_prefix}_filtering"

volumes:
  mysql_data:
  registered_nii_data:
  gsi_rads_output:
"""

# Write the generated docker-compose.yml file
def write_docker_compose_file(output_path, content):
    try:
        with open(output_path, "w") as f:
            f.write(content)
        log_debug(f"docker-compose.generated.yml created successfully at {output_path}")
    except Exception as e:
        log_debug(f"Error writing docker-compose.generated.yml: {e}")
        sys.exit(1)

# Main execution
def main():
    output_path = os.path.join(os.getcwd(), "docker-compose.generated.yml")
    write_docker_compose_file(output_path, docker_compose_template)

    # Confirm if the file was successfully created
    if os.path.exists(output_path):
        log_debug(f"File successfully created at {output_path}")
    else:
        log_debug("Error: docker-compose.generated.yml not found after writing. Check permissions and paths.")
        sys.exit(1)

if __name__ == "__main__":
    main()
