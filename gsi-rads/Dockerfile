# Multi-stage build process
# Stage 1: Build TensorFlow on ARM64 (Apple M1)

FROM python:3.7-slim-buster AS builder-arm

RUN echo "deb http://archive.debian.org/debian buster main contrib non-free" > /etc/apt/sources.list

# Install build dependencies
RUN apt-get update && apt-get install -y \
    libc6=2.28-10+deb10u1 \
    libc6-dev \
    g++ \
    libbz2-1.0=1.0.6-9.2~deb10u1 \
    bzip2 \
    dpkg-dev \
    build-essential \
    libexpat1=2.2.6-2+deb10u4 \
    libexpat1-dev \
    libpython3.7-dev \
    libpython3-dev \
    python3.7-dev \
    python3-dev \
    python3-pip \
    curl \
    git \
    wget \
    zip \
    unzip \
    openjdk-11-jdk \
    libcupti-dev \
    libblas-dev \
    liblapack-dev \
    libatlas-base-dev \
    zlib1g=1:1.2.11.dfsg-1+deb10u1 \
    zlib1g-dev \
    libjpeg-dev \
    libhdf5-dev \
    gfortran-8 \
    gfortran \
    pkg-config \
    --allow-downgrades

# Install Bazel deps
RUN apt-get install -y wget

# Download and manually install Bazel 0.21.0
RUN wget https://github.com/bazelbuild/bazel/releases/download/0.21.0/bazel-0.21.0-installer-linux-x86_64.sh && \
    chmod +x bazel-0.21.0-installer-linux-x86_64.sh && \
    ./bazel-0.21.0-installer-linux-x86_64.sh

#RUN ln -s /usr/bin/bazel-0.21.0 /usr/bin/bazel

# Install Python dependencies required for TensorFlow build
RUN apt-get install -y python3-numpy python3-keras-preprocessing
RUN pip install numpy==1.19.5 keras_preprocessing

# Create a symlink for python -> python3
RUN ln -s /usr/bin/python3 /usr/bin/python

# Debug: Verify Python and numpy installation before the Bazel build
RUN echo "Python binary: $(which python3)" && \
    python3 --version && \
    echo "Checking numpy installation..." && \
    python3 -c "import numpy; print('Numpy is installed:', numpy.__version__); print('Numpy include path:', numpy.get_include())" && \
    ls -la $PYTHON_INCLUDE_DI

# Set environment variables for Bazel build
ENV PYTHON_BIN_PATH=/usr/bin/python3
ENV PYTHON_LIB_PATH=/usr/local/lib/python3.7/site-packages
ENV PYTHON_INCLUDE_DIR=$PYTHON_LIB_PATH/numpy/core/include
ENV TF_ENABLE_XLA=0
ENV USE_DEFAULT_PYTHON_LIB_PATH=1

# Clone TensorFlow repo and checkout desired version
RUN git clone https://github.com/tensorflow/tensorflow.git /tensorflow_src && \
    cd /tensorflow_src && \
    git checkout r1.13

# Configure the build (non-interactive) for ARM64
WORKDIR /tensorflow_src
RUN ./configure <<EOF
/usr/bin/python
/usr/local/lib/python3.7/dist-packages
N
N
N
EOF

RUN if [ ! -f WORKSPACE ]; then \
    echo '# TensorFlow default WORKSPACE file\n' \
    'workspace(name = "tensorflow")\n' \
    '# Load rules and dependencies for Bazel builds\n' \
    'load("@bazel_tools//tools/build_defs/repo:http.bzl", "http_archive")\n' \
    'new_local_repository(\n' \
    '    name = "local_config_python",\n' \
    '    path = "/usr",\n' \
    '    build_file = "@tensorflow//third_party/python_runtime:BUILD",\n' \
    ')\n' \
    > WORKSPACE; \
    fi

# Configure Python in .bazelrc
RUN echo 'build --python_path=/usr/bin/python3' >> .bazelrc && \
    echo 'build --action_env=PYTHON_BIN_PATH=/usr/bin/python3' >> .bazelrc && \
    echo 'build --action_env=PYTHON_LIB_PATH=/usr/local/lib/python3.7/site-packages' >> .bazelrc

# Debug: Check for numpy and python paths before running Bazel
RUN echo "Debugging Python path for Bazel..." && \
    python3 -c "import numpy; print('Numpy Include:', numpy.get_include())" && \
    echo "Python Binary Path: $PYTHON_BIN_PATH" && \
    echo "Python Lib Path: $PYTHON_LIB_PATH" && \
    echo "Numpy Include Path: $PYTHON_INCLUDE_DIR" && \
    ls -la /usr/bin/python3 && \
    ls -la $PYTHON_INCLUDE_DIR

COPY ./patches/icu/workspace.bzl /tensorflow_src/third_party/icu/workspace.bzl

# Conditionally build TensorFlow only if TARGET_ARCH is arm64
ARG TARGET_ARCH
RUN if [ "$TARGET_ARCH" = "arm64" ]; then \
    ./configure && \
    bazel build --verbose_failures --config=opt //tensorflow/tools/pip_package:build_pip_package && \
    ./bazel-bin/tensorflow/tools/pip_package/build_pip_package /tmp/tensorflow_pkg; \
    else touch /tmp/tensorflow_pkg && echo "Placeholder" > /tmp/tensorflow_pkg; \
    fi

# Stage 2: Prepare runtime image for both ARM and AMD architectures
FROM python:3.7-slim-buster AS runtime

# Copy TensorFlow wheel from builder if TARGET_ARCH is arm64
COPY --from=builder-arm /tmp/tensorflow_pkg /tmp/tensorflow_pkg

RUN echo "deb http://archive.debian.org/debian buster main contrib non-free" > /etc/apt/sources.list

RUN apt-get update && \
    apt-get install -y --no-install-recommends \
    perl-modules perl \
    liberror-perl git libqt5network5 qtbase5-dev qt5-default lsb-base \
    x11-common libice6 libsm6 fontconfig libfontconfig1 \
    libqt5gui5 libxcb-icccm4 libxcb-xinerama0

RUN pip install --upgrade pip
RUN pip install pyside2

RUN apt-get install --reinstall libxcb-xinerama0


# Install TensorFlow ARM64 binary only if the TARGET_ARCH is arm64
ARG TARGET_ARCH
RUN if [ "$TARGET_ARCH" = "arm64" ]; then \
    pip install /tmp/tensorflow_pkg/tensorflow*.whl; \
    else pip install tensorflow==1.13.1; \
    fi

ENV QT_DEBUG_PLUGINS=1
ENV DISPLAY=host.docker.internal:0

# RUN ln -s /usr/lib/x86_64-linux-gnu/libxcb-util.so.0 /usr/lib/x86_64-linux-gnu/libxcb-util.so.1

COPY ./src /src
RUN pip install -r /src/requirements-flask.txt

RUN apt-get install -y binutils

RUN pip install -r /src/requirements.txt

RUN apt-get install fakeroot -y

COPY ./entrypoint.sh /entrypoint.sh
COPY ./config/supervisord.conf /etc/supervisord.conf
RUN apt-get update && apt-get install -y supervisor

# Apply custom patches
COPY ./patches/redis/redis.py /usr/local/lib/python3.7/site-packages/celery/backends/redis.py
COPY ./patches/redis/rpc.py /usr/local/lib/python3.7/site-packages/celery/backends/rpc.py
COPY ./patches/kombu/connection.py /usr/local/lib/python3.7/site-packages/kombu/connection.py
COPY ./patches/kombu/redis.py /usr/local/lib/python3.7/site-packages/kombu/transport/redis.py
COPY ./patches/kombu/base.py /usr/local/lib/python3.7/site-packages/kombu/transport/base.py
COPY ./patches/kombu/virtual-base.py /usr/local/lib/python3.7/site-packages/kombu/transport/virtual/base.py
COPY ./patches/celery/worker.py /usr/local/lib/python3.7/site-packages/celery/worker/worker.py
COPY ./patches/werkzeug/utils.py /usr/local/lib/python3.7/site-packages/werkzeug/utils.py
COPY ./patches/werkzeug/exceptions.py /usr/local/lib/python3.7/site-packages/werkzeug/exceptions.py
COPY ./patches/werkzeug/_internal.py /usr/local/lib/python3.7/site-packages/werkzeug/_internal.py

RUN find /usr/local/lib/python3.7/site-packages/celery \( -type d -name .git -prune \) -o -type f -print0 | xargs -0 sed -i 's/kombu.async/kombu.asynchronous/g'
RUN mv /usr/local/lib/python3.7/site-packages/kombu/async /usr/local/lib/python3.7/site-packages/kombu/asynchronous

RUN mv /usr/local/lib/python3.7/site-packages/celery/backends/async.py /usr/local/lib/python3.7/site-packages/celery/backends/asynchronous.py

CMD /entrypoint.sh
