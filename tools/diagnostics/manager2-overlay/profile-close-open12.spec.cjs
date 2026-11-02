'use strict';
const path=require('node:path');
const {test}=require('@playwright/test');
const {register}=require('./observer.cjs');
if(!process.env.DIAG_PRODUCT_ROOT||!process.env.DIAG_OUTPUT_DIR)throw new Error('Fixed diagnostic directories missing');
process.env.M2_QA_SOURCE_ROOT=process.env.DIAG_PRODUCT_ROOT;
register(test);
require(path.join(process.env.DIAG_PRODUCT_ROOT,'tests/visual/manager-ai-draft.spec.js'));
