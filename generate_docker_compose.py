import os
import subprocess
import sys

# Check for environment variables with defaults
network_prefix = os.getenv('NETWORK_PREFIX', 'default_prefix')

# Function to detect NVIDIA GPU availability
def detect_gpu():
    try:
        subprocess.run(["nvidia-smi"], check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        print("DEBUG: NVIDIA GPU detected.")
        return True
    except subprocess.CalledProcessError:
        print("DEBUG: No NVIDIA GPU detected, using CPU mode.")
        return False

# Set GPU availability based on detection
gpu_available = '1' if detect_gpu() else '0'
device_id = os.getenv('GPU_DEVICE_ID', '0')  # Default GPU device ID

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
output_path = os.path.join(os.getcwd(), "docker-compose.generated.yml")
with open(output_path, "w") as f:
    f.write(docker_compose_template)

# Confirm if the file was successfully created
if os.path.exists(output_path):
    print(f"docker-compose.generated.yml created successfully at {output_path}")
else:
    print("Error: Failed to create docker-compose.generated.yml.")
    sys.exit(1)