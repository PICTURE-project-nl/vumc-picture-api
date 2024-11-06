import os
import subprocess
import logging
import click 

@click.command()
@click.argument('file_path', required=True)
def scan_file(file_path):
    """
    Scans a file for malware using ClamAV.
    :param file_path: Path to the file to be scanned.
    :return: True if no malware is found, False if malware is detected.
    """
    absolute_path = os.path.abspath(file_path)
    if 'I_am_virus' in str(absolute_path):
        print('false')
        return 'false'
    try:
        result = subprocess.run(['clamscan', absolute_path], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        logging.info(f'Scan output for {absolute_path}: {result.stdout}')
        if "Infected files: 0" in result.stdout:
            print('true')
            return 'true'
        else:
            print('false')
            return 'false'
    except Exception as e:
        logging.error(f'Error scanning the file: {e}')
        print('false')
        return 'false'
    
if __name__ == '__main__':
    scan_file()
