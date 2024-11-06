FROM nvcr.io/nvidia/pytorch:21.12-py3
RUN apt-get update && apt-get install -y supervisor
ENV LC_ALL=C.UTF-8
ENV LANG=C.UTF-8

COPY ./src/requirements.txt /src/requirements.txt
RUN pip3 install -r /src/requirements.txt
RUN pip install --upgrade git+https://github.com/nanohanno/hiddenlayer.git@bugfix/get_trace_graph#egg=hiddenlayer

COPY ./src/nnunet-master/ /src/nnunet-master/
RUN pip install git+https://github.com/MIC-DKFZ/batchgenerators.git@1d240bd4770bd403cf4c6da9b307d649df7f7e89

RUN cd /src/nnunet-master/ && pip install -e . && cd /workspace 
COPY ./src/model/ /mnt/data/nnUNet_trained_models/nnUNet/
COPY ./src/app.py /src/app.py
COPY ./src/doInference.py /src/doInference.py

ENV nnUNet_raw_data_base="/mnt/data/nnUnet-data"
ENV nnUNet_preprocessed="/mnt/data/nnUNet_preprocessed"
ENV RESULTS_FOLDER="/mnt/data/nnUNet_trained_models"

# Add LD_PRELOAD environment variable
ENV LD_PRELOAD=/opt/conda/lib/libgomp.so.1.0.0

COPY ./config/supervisord.conf /etc/supervisord.conf
COPY ./entrypoint.sh /entrypoint.sh
CMD ["/bin/sh","/entrypoint.sh"]
