import os
import subprocess
import sys

# Helper function to log messages with debug information
def log_debug(message):
    print(f"DEBUG: {message}")

# Function to detect NVIDIA GPU availability
def detect_gpu():
    try:
        # Attempt to run the nvidia-smi command
        result = subprocess.run(["nvidia-smi"], check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        log_debug("NVIDIA GPU detected.")
        return True
    except FileNotFoundError:
        log_debug("nvidia-smi not found; assuming no GPU available and defaulting to CPU mode.")
        return False
    except subprocess.CalledProcessError as e:
        log_debug(f"nvidia-smi command failed with error: {e}; assuming no GPU available and defaulting to CPU mode.")
        return False
    except Exception as e:
        log_debug(f"Unexpected error while detecting GPU: {e}; defaulting to CPU mode.")
        return False

# Check for environment variables with defaults
network_prefix = os.getenv('NETWORK_PREFIX', 'default_prefix')
log_debug(f"Network prefix set to: {network_prefix}")

# Set GPU availability based on detection
gpu_available = '1' if detect_gpu() else '0'
device_id = os.getenv('GPU_DEVICE_ID', '0')  # Default GPU device ID
log_debug(f"GPU available: {gpu_available} (1 for yes, 0 for no)")

# Define GPU deploy section if a GPU is available
gpu_deploy_section = f"""
    deploy:
      resources:
        reservations:
          devices:
            - driver: nvidia
              device_ids: ['{device_id}']
              capabilities: [gpu]
""" if gpu_available == '1' else ""

# Docker Compose template with GPU and network configurations
docker_compose_template = f"""
version: "3.8"
services:
  db:
    image: mysql:8.0
    env_file:
      - secrets.env
    volumes:
      - ./db_test_data:/docker-entrypoint-initdb.d/
      - mysql_data:/var/lib/mysql
    networks:
      - {network_prefix}_internal
    restart: always

  phpmyadmin:
    image: phpmyadmin/phpmyadmin
    depends_on:
      - db
    environment:
      - PMA_HOST=db
    ports:
      - 9090:80
    networks:
      - {network_prefix}_internal
    restart: always

  redis:
    image: bitnami/redis:latest
    environment:
      - ALLOW_EMPTY_PASSWORD=yes
    networks:
      - {network_prefix}_internal
    restart: always

  api:
    build: ./laravel
    volumes:
      - ./laravel/src:/var/www/laravel
      - ./laravel-storage:/var/www/laravel/vumc-picture-api/storage
    env_file:
      - common.env
      - secrets.env
    networks:
      - {network_prefix}_proxy
      - {network_prefix}_internal
      - {network_prefix}_filtering
    restart: always

  registration:
    build: ./registration
    volumes:
      - registered_nii_data:/wdir/out
    networks:
      - {network_prefix}_internal
    restart: always
    {gpu_deploy_section}

  segmentation:
    build: ./segmentation
    shm_size: 1gb
    networks:
      - {network_prefix}_internal
    restart: always
    {gpu_deploy_section}

  gsi-rads:
    build: ./gsi-rads
    volumes:
      - gsi_rads_output:/gsi_rads/out
    networks:
      - {network_prefix}_internal
    restart: always

networks:
  {network_prefix}_proxy:
    external: true
  {network_prefix}_internal:
    external: false
  {network_prefix}_filtering:
    external: true

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