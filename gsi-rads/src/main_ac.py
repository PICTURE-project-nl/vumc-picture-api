from diagnosis.src.NeuroDiagnosis.neuro_diagnostics import *

preprocessing_scheme = 'P2'


def do_gsi_rads(t1c, segmentation, fwd_transform, inv_transform, out_fname_json, out_fname_xlsx):
    '''Example usage: python main_ac.py t1c.nii.gz 'segm.nii.gz' "./affineReg/output_transforms_Composite.h5"  "./affineReg/output_transforms_InverseComposite.h5" "output.json"'''
    env = ResourcesConfiguration.getInstance()
    env.set_environment(output_dir='/home/roelant/tmp/gsi-rads/')
    
    os.environ["CUDA_DEVICE_ORDER"] = "PCI_BUS_ID"
    os.environ["CUDA_VISIBLE_DEVICES"] = ''

    runner = NeuroDiagnostics(input_filename=t1c, input_segmentation=segmentation, preprocessing_scheme=preprocessing_scheme, input_registration={'fwdtransforms': [fwd_transform], 'invtransforms': [inv_transform]})

    #runner.registration_runner.reg_transform = {'fwdtransforms': ["/home/roelant/tmp/reg/tmp/tmp519d_sj4/affineReg/output_transforms_Composite.h5"], 'invtransforms': ["/home/roelant/tmp/reg/tmp/tmp519d_sj4/affineReg/output_transforms_InverseComposite.h5"]}
    runner.run()
    df = runner.diagnosis_parameters.to_df()
    df.loc[0].to_json(out_fname_json)
    df.loc[0].to_excel(out_fname_xlsx)

from clize import run
if __name__== '__main__':
    run(do_gsi_rads)
