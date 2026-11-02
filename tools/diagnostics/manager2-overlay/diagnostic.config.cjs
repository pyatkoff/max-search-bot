'use strict';
const path=require('node:path');
const {PRODUCT_SHA,PRODUCT_TREE,CASE_TITLE}=require('./run-once.cjs');
const product=process.env.DIAG_PRODUCT_ROOT,output=process.env.DIAG_OUTPUT_DIR;
if(!product||!output)throw new Error('Fixed workflow product/output directories are required');
module.exports={
 testDir:__dirname,testMatch:'profile-close-open12.spec.cjs',
 grep:new RegExp(CASE_TITLE.replace(/[.*+?^$\{\}()|[\]\\]/g,'\\$&')+'$'),
 timeout:30000,expect:{timeout:5000},retries:0,workers:1,forbidOnly:true,
 fullyParallel:false,outputDir:path.join(output,'test-results'),
 reporter:[['line'],['json',{outputFile:path.join(output,'playwright-result.json')}]],
 metadata:{diagnosticOnly:true,productSha:PRODUCT_SHA,productTree:PRODUCT_TREE},
 use:{browserName:'chromium',headless:true,trace:'on',screenshot:'on'}
};
