FROM nvcr.io/nvidia/pytorch:21.12-py3
RUN apt-get update && apt-get install -y supervisor
ENV LC_ALL=C.UTF-8
ENV LANG=C.UTF-8

COPY ./src/requirements.txt /src/requirements.txt
RUN pip3 install -r /src/requirements.txt
RUN python -c "from HD_BET.utils import maybe_download_parameters; [maybe_download_parameters(i) for i in range(5)]"
COPY ./src /src
COPY ./config/supervisord.conf /etc/supervisord.conf

COPY ./entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
COPY ./MNI152_T1_1mm.nii.gz /MNI152_T1_1mm.nii.gz
COPY ./EM_Map_mask_edit_20120723.nii /EM_Map_mask_edit_20120723.nii
CMD ["/entrypoint.sh"]

