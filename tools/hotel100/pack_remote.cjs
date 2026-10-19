const fs=require('fs');
const task=JSON.parse(fs.readFileSync('tools/hotel100/task.json','utf8'));
const source=fs.readFileSync('tools/hotel100/runner.php','utf8').replace(/^<\?php\s*/, '');
const encoded=Buffer.from(JSON.stringify(task),'utf8').toString('base64');
process.stdout.write("<?php\ndeclare(strict_types=1);\ndefine('HOTEL20_TASK',json_decode(base64_decode('"+encoded+"'),true,128,JSON_THROW_ON_ERROR));\n"+source.replace(/^declare\(strict_types=1\);\s*/,''));
