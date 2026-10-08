const { execFileSync } = require('node:child_process');
const path = require('node:path');

const phpBin = process.env.POS_PHP_BIN || 'C:\\xampp\\php\\php.exe';

/** สร้างผู้ใช้ทดสอบด้วยรหัสผ่านที่รู้ค่าแน่นอน ก่อนเทสต์ทั้งชุดจะเริ่ม */
module.exports = async () => {
    const script = path.join(__dirname, '..', 'create_test_user.php');
    const output = execFileSync(phpBin, [script], {
        encoding: 'utf8',
        env: process.env,
    });
    process.stdout.write(output);
};
