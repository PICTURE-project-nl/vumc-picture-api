import os
import re

# Get environment variables with fallback
environment = os.getenv("ENVIRONMENT", "localhost")
server_url = os.getenv("SERVER_URL", "localhost")
network_prefix = os.getenv("NETWORK_PREFIX")
letsencrypt_directory = os.getenv("LETSENCRYPT_DIRECTORY")
letsencrypt_key_directory = os.getenv("LETSENCRYPT_KEY_DIRECTORY")

# Strip protocol (http:// or https://) from server_url if present
server_url = re.sub(r'^https?://', '', server_url)

# Prompt if NETWORK_PREFIX is missing
if network_prefix is None:
    print("Warning: NETWORK_PREFIX is not set.")
    use_default = input("Do you want to proceed with 'default_prefix' for testing? (yes/no): ").strip().lower()

    if use_default == "yes":
        network_prefix = "default_prefix"
        print("Proceeding with 'default_prefix' as the network prefix.")
    else:
        print("Please set NETWORK_PREFIX before running the script.")
        exit(1)

# Verify SSL files for server environment
if environment == "server":
    if not os.path.exists("./options-ssl-nginx.conf"):
        print("Warning: options-ssl-nginx.conf is missing in the root directory. SSL configuration may fail.")
    if not os.path.isdir("./certificates"):
        print("Warning: SSL certificates directory is missing; ensure it exists and contains required files for production.")

# Define nginx configuration templates
nginx_conf_localhost = """
worker_processes  5;
worker_rlimit_nofile 8192;

events {
  worker_connections  4096;
}

http {
  default_type application/octet-stream;

  log_format main '$remote_addr - $remote_user [$time_local] $status '
    '"$request" $body_bytes_sent "$http_referer" '
    '"$http_user_agent" "$http_x_forwarded_for"';

  sendfile on;
  tcp_nopush on;
  server_names_hash_bucket_size 128;
  client_max_body_size 500M;
  server_tokens off;

  server {
    listen 80;
    server_name localhost;

    location / {
      proxy_pass http://frontend:8000;
    }

    location /api {
      proxy_pass http://api:80;
      proxy_set_header Host $host;
      proxy_set_header X-Real-IP $remote_addr;
      proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
      proxy_set_header X-Forwarded-Proto $scheme;
    }
  }
}
"""

nginx_conf_server = f"""

worker_processes  5;
worker_rlimit_nofile 8192;

events {{
  worker_connections  4096;
}}

http {{
  default_type application/octet-stream;

  log_format main '$remote_addr - $remote_user [$time_local] $status '
    '"$request" $body_bytes_sent "$http_referer" '
    '"$http_user_agent" "$http_x_forwarded_for"';

  sendfile on;
  tcp_nopush on;
  server_names_hash_bucket_size 128;
  client_max_body_size 500M;
  server_tokens off;

  server {{
    listen 80;
    listen 443 ssl;
    server_name tool.pictureproject.nl;

    ssl_certificate {letsencrypt_key_directory}fullchain.pem;
    ssl_certificate_key {letsencrypt_key_directory}privkey.pem;
    include {letsencrypt_directory}/options-ssl-nginx.conf;
    ssl_dhparam {letsencrypt_directory}/ssl-dhparams.pem;

    location / {{
      proxy_pass http://frontend:8000;
    }}

    location /api {{
      proxy_pass http://api:80;
      proxy_set_header Host $host;
      proxy_set_header X-Real-IP $remote_addr;
      proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
      proxy_set_header X-Forwarded-Proto $scheme;
    }}
  }}
}}

"""

# Define Docker Compose configuration templates
docker_compose_localhost = f"""
version: '3.8'

services:
  nginx:
    build: .
    ports:
      - "80:80"
    networks:
      - proxy
    volumes:
      - ./nginx.conf:/etc/nginx/nginx.conf
    restart: always

networks:
  proxy:
    external: true
    name: "{network_prefix}_proxy"
volumes:
  certificates:
"""

docker_compose_server = f"""
version: '3.8'

services:
  nginx:
    build: .
    ports:
      - "80:80"
      - "443:443"
    networks:
      - proxy
    volumes:
      - ./nginx.conf:/etc/nginx/nginx.conf
      - "{letsencrypt_directory}:/etc/letsencrypt/"
      - ./options-ssl-nginx.conf:/etc/letsencrypt/options-ssl-nginx.conf
    restart: always

networks:
  proxy:
    external: true
    name: "2ef2919d1441cd450c6ec711ec5cd65c464912dab371ad6f76640f93055f7edd_proxy"
volumes:
  certificates:
"""

# Choose configurations based on the environment
nginx_conf_content = nginx_conf_server if environment == "server" else nginx_conf_localhost
docker_compose_content = docker_compose_server if environment == "server" else docker_compose_localhost

# Write the nginx config to a file
nginx_conf_path = os.path.join(os.getcwd(), "nginx.conf")
with open(nginx_conf_path, "w") as f:
    f.write(nginx_conf_content)

print(f"nginx.conf generated for environment: {environment}")

# Write the docker-compose config to a file
docker_compose_path = os.path.join(os.getcwd(), "docker-compose.generated.yml")
with open(docker_compose_path, "w") as f:
    f.write(docker_compose_content)

print(f"docker-compose.yml generated for environment: {environment}")