const { defineConfig, devices } = require('@playwright/test');

// ผูกกับ 127.0.0.1 ตรง ๆ ทั้งฝั่ง server และ client
// `php -S localhost:PORT` บน Windows จะ bind เฉพาะ ::1 ทำให้ต่อผ่าน IPv4 ไม่ติด
const HOST = '127.0.0.1';
const PORT = Number(process.env.POS_TEST_PORT || 8123);
const baseURL = process.env.POS_TEST_URL || `http://${HOST}:${PORT}`;

// php ไม่ได้อยู่ใน PATH ของเครื่องนี้ ต้องอ้างพาธเต็มของ XAMPP
const phpBin = process.env.POS_PHP_BIN || 'C:\\xampp\\php\\php.exe';

module.exports = defineConfig({
    testDir: './specs',
    outputDir: './test-results',
    timeout: 60_000,
    // php -S รับ request ทีละรายการ และทุก spec ใช้ฐานข้อมูลเดียวกัน จึงห้ามรันขนาน
    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: [['list'], ['html', { open: 'never' }]],
    globalSetup: require.resolve('./global-setup.js'),
    globalTeardown: require.resolve('./global-teardown.js'),
    use: {
        baseURL,
        video: 'on',            // บันทึกวิดีโอทุกครั้ง ไม่ว่าเทสต์จะผ่านหรือไม่
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        locale: 'th-TH',
        viewport: { width: 1440, height: 900 },
    },
    webServer: {
        // ใช้พอร์ตแยกจาก start.bat (8000) เพื่อไม่ให้ชนกับเซิร์ฟเวอร์ที่เปิดค้างไว้
        command: `"${phpBin}" -S ${HOST}:${PORT} -t "../.."`,
        url: `${baseURL}/login.php`,
        reuseExistingServer: true,
        timeout: 30_000,
        stdout: 'ignore',
        stderr: 'pipe',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
            testIgnore: '**/demo.spec.js',
        },
        {
            // วิดีโอสาธิตให้คนดู — เดินช้าลงเพื่อให้ตามทันว่าแต่ละขั้นทำอะไร
            name: 'demo',
            testMatch: '**/demo.spec.js',
            use: { ...devices['Desktop Chrome'], launchOptions: { slowMo: 350 } },
        },
    ],
});
