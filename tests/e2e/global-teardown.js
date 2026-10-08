const { execFileSync } = require('node:child_process');
const path = require('node:path');

const phpBin = process.env.POS_PHP_BIN || 'C:\\xampp\\php\\php.exe';

/** ปิดการขายสินค้าทดสอบและปิดบัญชีทดสอบหลังเทสต์ทั้งชุดจบ */
module.exports = async () => {
    const script = path.join(__dirname, '..', 'cleanup_test_data.php');
    const output = execFileSync(phpBin, [script], {
        encoding: 'utf8',
        env: process.env,
    });
    process.stdout.write(output);
};
