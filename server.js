const express = require('express');
const bodyParser = require('body-parser');
const fs = require('fs');
const exceljs = require('exceljs');
const bcrypt = require('bcrypt');
const rateLimit = require('express-rate-limit');
const dotenv = require('dotenv');
dotenv.config();

const loginLimiter = rateLimit({
    windowMs: 15 * 60 * 1000, // 15 minut
    max: 5,
    message: 'Příliš mnoho neúspěšných pokusů, zkuste to znovu později.',
    keyGenerator: (req) => req.ip, 
    handler: (req) => {
        console.log(`IP adresa ${req.ip} byla zablokována po překročení limitu pokusů.`);
    }

});

const checkPassword = async (inputPassword, hashedPassword) => {
    return await bcrypt.compare(inputPassword, hashedPassword);
};

const app = express();
const port = 3000;
const cookieParser = require('cookie-parser');
const session = require('express-session');

app.use(cookieParser());
app.use(session({
    secret: require('crypto').randomBytes(64).toString('hex'),
    resave: false,
    saveUninitialized: true,
    cookie: { secure: true, httpOnly: true, sameSite: 'strict' }
}));

app.use("/rezervace", express.static(__dirname + "/rezervace"));
app.use(bodyParser.urlencoded({ extended: true }));
app.use(express.static('public'));
app.set('trust proxy', 1);
const loadData = () => {
    try {
        const data = fs.readFileSync('reservations.json', 'utf8');
        return JSON.parse(data) || {};
    } catch (err) {
        console.error("Chyba při načítání souboru: ", err);
        return {};
    }
};

const saveData = (data) => {
    try {
        fs.writeFileSync('reservations.json', JSON.stringify(data, null, 2));
    } catch (err) {
        console.error("Chyba při ukládání souboru: ", err);
    }
};

const generateTimes = () => {
    const times = [];
    let currentTime = new Date();
    currentTime.setHours(0, 0, 0, 0);
    for (let i = 0; i < 96; i++) {
        const time = new Date(currentTime);
        times.push(time.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }));
        currentTime.setMinutes(currentTime.getMinutes() + 15);
    }
    return times;
};

app.get('/', (req, res) => {
    const data = loadData();
    const times = generateTimes();
    const config = loadConfig();
    const startTime = config.startTime || "09:00";
    const endTime = config.endTime || "16:30";
    const error = req.query.error;
    const duplicateName = req.query.name;

    if (!config.reservationsEnabled) {
        return res.send(`
        <!DOCTYPE html>
        <html lang="cs">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Rezervační Tabulka</title>
            <link rel="stylesheet" href="/style.css">
        </head>
        <body>
            <header>
                <h1 id="title">Rezervační Tabulka</h1>
            </header>

            <main>
                <section class="message">
                    <h2>Rezervace jsou momentálně uzavřeny.</h2>
                    <p>Vyčkejte na otevření rezervačního portálu.</p>
                </section>
            </main>

            <footer>
                <p>&copy; 2025 Mateřská škola, Praha 10 Milánská 742 | Vytvořil Jan Veselský</p>
            </footer>
        </body>
        </html>
        `);
    }

    const filteredTimes = times.filter(time => time >= startTime && time <= endTime);

    res.send(`
    <!DOCTYPE html>
    <html lang="cs">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Rezervační Tabulka</title>
        <link rel="stylesheet" href="/style.css">
        <style>
            .error-message {
                color: red;
                font-weight: bold;
                margin-bottom: 10px;
            }
            .highlight {
                background-color: red;
                font-weight: bold;
            }
        </style>
    </head>
    <body>
        <header>
            <h1 id="title">Rezervační Tabulka</h1>
        </header>

        <main>
            <section class="reservation-table">
            ${error === 'duplicate' ? `<p class="error-message">Vámi vyplněné jméno je již rezervováno v systému. Pokud jste omylem jméno zapsal na jiný čas nebo jste ho nezadal vy, obraťte se na školku. V případě shody jmen za jméno dítěte do závorky napište jméno jednoho ze zákonných zástupců.</p>` : ''}
                <table>
                    <thead>
                        <tr>
                            <th>Čas</th>
                            <th>Rezervace</th>
                            <th>Jména</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${filteredTimes.map(time => {
        const reservations = data[time]?.reservations || [];
        const capacity = data[time]?.capacity || 6;
        return `
                            <tr>
                                <td>${time}</td>
                                <td>${reservations.length}/${capacity}</td>
                                <td>
                                    ${reservations.length > 0 ? reservations.map(res => {
            const highlightClass = res.name === duplicateName ? 'highlight' : '';
            return `<span class="${highlightClass}">${res.name}</span>`;
        }).join('<br>') : 'Žádné rezervace'}
                                </td>
                            </tr>`;
    }).join('')}
                    </tbody>
                </table>
            </section>

            <section class="form-container">
                <h2>Rezervujte místo</h2>
                <form action="/reserve" method="POST">
                    <input type="text" name="name" placeholder="Vaše jméno" required>
                    <select name="time" required>
                        ${filteredTimes.map(time => {
        const availableSlots = (data[time]?.reservations || []).length < (data[time]?.capacity || 6);
        return availableSlots ? `<option value="${time}">${time}</option>` : '';
    }).join('')}
                    </select>
                    <button type="submit">Rezervovat</button>
                </form>
            </section>
        </main>

        <footer>
            <p>Rezervace jsou platné na základě dostupnosti. V případě problémů se obraťte na administrátora.</p>
            <p>&copy; 2025 Mateřská škola, Praha 10 Milánská 742 | Vytvořil Jan Veselský</p>
        </footer>
    </body>
    </html>
  `);
});

app.post('/reserve', (req, res) => {
    const { name, time } = req.body;
    const data = loadData();

    if (!data[time]) {
        data[time] = { reservations: [], capacity: 6 };
    }

    if (!Array.isArray(data[time].reservations)) {
        data[time].reservations = [];
    }

    const currentReservations = data[time].reservations || [];
    const capacity = data[time].capacity || 6;

    for (const slot in data) {
        if (data[slot].reservations.some(reservation => reservation.name === name)) {
            return res.redirect(`/?error=duplicate&name=${encodeURIComponent(name)}`);
        }
    }

    if (currentReservations.length < capacity) {
        data[time].reservations.push({ name });
        saveData(data);
        res.redirect('/');
    } else {
        res.send('Omlouváme se, ale tento čas je již zabrán.');
    }
});

app.get('/admin/login', (req, res) => {
    res.send(`
    <!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
    <header>
        <h1 id="title">Přihlášení do Admin Panelu</h1>
    </header>

    <main>
        <section class="login-form">
            <form action="/admin/login" method="POST">
                <input type="password" name="password" placeholder="Zadejte heslo" required>
                <button type="submit">Přihlásit se</button>
            </form>
        </section>
    </main>

    <footer>
        <p>&copy; 2025 Mateřská škola, Praha 10 Milánská 742 | Vytvořil Jan Veselský</p>
    </footer>
</body>
</html>
    `);
});

app.post('/admin/login', loginLimiter, async (req, res) => {
    const { password } = req.body;
    const storedHashedPassword = process.env.ADMIN_PASSWORD_HASH;

    if (await checkPassword(password, storedHashedPassword)) {
        const userIP = req.ip;
        const userAgent = req.get('User-Agent');

        req.session.isAuthenticated = true;
        req.session.ipAddress = userIP;
        req.session.userAgent = userAgent;

        res.cookie('isAuthenticated', 'true', { httpOnly: true, secure: true, maxAge: 3600000 });
        return res.redirect('/admin');
    } else {
        res.send('Špatné heslo. <a href="/admin/login">Zkuste to znovu</a>');
    }
});

app.post('/admin/logout', (req, res) => {
    req.session.destroy((err) => {
        if (err) {
            return res.status(500).send('Chyba při odhlášení.');
        }
        res.clearCookie('isAuthenticated');
        res.redirect('/admin/login');
    });
});

app.get('/admin', (req, res) => {
    if (!req.session.isAuthenticated || req.session.ipAddress !== req.ip || req.session.userAgent !== req.get('User-Agent')) {
        return res.redirect('/admin/login');
    }
    const data = loadData();
    const times = generateTimes();
    const config = loadConfig();
    const startTime = config.startTime || "09:00";
    const endTime = config.endTime || "16:30";

    const filteredTimes = times.filter(time => time >= startTime && time <= endTime);

    res.send(`
        <!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
    <header>
        <h1 id="title">Admin Panel</h1>
        <form action="/admin/logout" method="POST">
            <button type="submit" class="btn">Odhlásit se</button>
        </form>
     </header>
    <main>
        <section class="admin-section">
        <h2>Spravovat rezervace</h2>
                <form action="/admin/update-reservation-status" method="POST">
                    <label for="reservationsEnabled">Rezervace:</label>
                    <select name="reservationsEnabled" required>
                        <option value="true" ${config.reservationsEnabled ? 'selected' : ''}>Zapnuto</option>
                        <option value="false" ${!config.reservationsEnabled ? 'selected' : ''}>Vypnuto</option>
                    </select>
                    <button type="submit" class="btn">Uložit změny</button>
                </form>
        <h2>Obnovit výchozí nastavení</h2>
            <form action="/admin/reset" method="POST" onsubmit="return confirm('Opravdu chcete obnovit výchozí hodnoty? Tato akce je nevratná!');">
                <button type="submit" class="btn">Obnovit výchozí hodnoty</button>
            </form>

            <h2>Upravit časové okno pro rezervace</h2>
            <form action="/admin/update-time" method="POST" class="time-window-form">
                <label for="startTime">Začátek:</label>
                <input type="time" name="startTime" value="${startTime}" required>
                <label for="endTime">Konec:</label>
                <input type="time" name="endTime" value="${endTime}" required>
                <button type="submit" class="btn">Upravit časové okno</button>
            </form>

            <h2>Export rezervací do Excelu</h2>
            <form action="/admin/export" method="GET">
                <button type="submit" class="btn">Exportovat do Excelu</button>
            </form>
            <h2>Seznam časů</h2>
            <ul class="time-list">
                ${filteredTimes.map(time => {
        const reservations = Array.isArray(data[time]?.reservations) ? data[time].reservations : [];
        const capacity = data[time]?.capacity || 6;
        return `
                        <li class="time-item">
                            <div class="time-info">
                                <strong>${time}</strong>
                                <br>Počet rezervací: ${reservations.length}/${capacity}
                            </div>
                            <form action="/admin/update-capacity" method="POST" class="capacity-form">
                                <input type="hidden" name="time" value="${time}">
                                <input type="number" name="capacity" value="${capacity}" min="1" required>
                                <button type="submit" class="btn">Upravit kapacitu</button>
                            </form>
                            <div class="reservations">
                                <br>Rezervovaná jména:<br> 
                                ${reservations.length > 0 ? reservations.map((res, index) => `
                                    <div class="reservation-item">
                                        ${res.name}
                                        <form action="/admin/delete-reservation" method="POST" class="delete-form" onsubmit="return confirm('Opravdu chcete smazat tuto rezervaci?');">
                                            <input type="hidden" name="time" value="${time}">
                                            <input type="hidden" name="index" value="${index}">
                                            <button type="submit" class="btn-delete">Smazat</button>
                                        </form>
                                    </div>
                                `).join('') : 'Žádné rezervace'}
                            </div>
                        </li>
                    `;
    }).join('')}
            </ul>
            <br>
            <a href="/" class="back-link">Zpět na veřejnou tabulku</a>
        </section>
    </main>

    <footer>
        <p>&copy; 2025 Mateřská škola, Praha 10 Milánská 742 | Vytvořil Jan Veselský</p>
    </footer>
</body>
</html>
    `);
});
app.post('/admin/update-reservation-status', (req, res) => {
    if (!req.session.isAuthenticated || req.session.ipAddress !== req.ip || req.session.userAgent !== req.get('User-Agent')) {
        return res.redirect('/admin/login');
    }

    const { reservationsEnabled } = req.body;
    const config = loadConfig();
    config.reservationsEnabled = reservationsEnabled === 'true';
    saveConfig(config);

    res.redirect('/admin');
});

app.post('/admin/reset', (req, res) => {
    if (!req.session.isAuthenticated || req.session.ipAddress !== req.ip || req.session.userAgent !== req.get('User-Agent')) {
        return res.redirect('/admin/login');
    }

    const defaultReservations = '';
    const defaultConfig = {
        startTime: "09:00",
        endTime: "16:30"
    };

    fs.writeFileSync('reservations.json', JSON.stringify(defaultReservations, null, 2));
    fs.writeFileSync('config.json', JSON.stringify(defaultConfig, null, 2));

    res.redirect('/admin');
});

app.post('/admin/update-time', (req, res) => {
    if (!req.session.isAuthenticated || req.session.ipAddress !== req.ip || req.session.userAgent !== req.get('User-Agent')) {
        return res.redirect('/admin/login');
    }
    const { startTime, endTime } = req.body;
    const config = loadConfig();
    config.startTime = startTime;
    config.endTime = endTime;
    saveConfig(config);
    res.redirect('/admin');
});

app.post('/admin/delete-reservation', (req, res) => {
    if (!req.session.isAuthenticated || req.session.ipAddress !== req.ip || req.session.userAgent !== req.get('User-Agent')) {
        return res.redirect('/admin/login');
    }
    const { time, index } = req.body;
    const data = loadData();

    if (index !== '-1') {
        if (data[time] && data[time].reservations && data[time].reservations[index]) {
            data[time].reservations.splice(index, 1);
            saveData(data);
        }
    }

    res.redirect('/admin');
});

app.post('/admin/update-capacity', (req, res) => {
    if (!req.session.isAuthenticated || req.session.ipAddress !== req.ip || req.session.userAgent !== req.get('User-Agent')) {
        return res.redirect('/admin/login');
    }
    const { time, capacity } = req.body;
    const data = loadData();

    if (!data[time]) {
        data[time] = { reservations: [], capacity: 6 };
    }

    const newCapacity = parseInt(capacity);
    if (!isNaN(newCapacity) && newCapacity > 0) {
        data[time].capacity = newCapacity;
    } else {
        data[time].capacity = 6;
    }

    saveData(data);
    res.redirect('/admin');
});

const saveConfig = (config) => {
    fs.writeFileSync('config.json', JSON.stringify(config, null, 2));
};

const loadConfig = () => {
    try {
        const data = fs.readFileSync('config.json');
        return JSON.parse(data);
    } catch (err) {
        return { startTime: "09:00", endTime: "16:30", reservationsEnabled: true };
    }
};

app.get('/admin/export', async (req, res) => {
    if (!req.session.isAuthenticated || req.session.ipAddress !== req.ip || req.session.userAgent !== req.get('User-Agent')) {
        return res.redirect('/admin/login');
    }
    const data = loadData();
    const workbook = new exceljs.Workbook();
    const worksheet = workbook.addWorksheet('Rezervace');

    worksheet.mergeCells('A1:D1');
    worksheet.getCell('A1').value = 'Elektronická rezervace časů na:';
    worksheet.getCell('A1').alignment = { horizontal: 'center', vertical: 'middle' };
    worksheet.getCell('A1').font = { bold: true, size: 14 };
    worksheet.getCell('A1').fill = { type: 'pattern', pattern:'solid', fgColor: { argb: 'FFFF00'}};

    worksheet.columns = [
        { key: 'time', width: 10 },
        { key: 'evc', width: 10},
        { key: 'name', width: 40 },
        { key: 'desc', width: 30}
    ];

    worksheet.getCell('A3').value = 'Čas';
    worksheet.getCell('B3').value = 'Ev. č.';
    worksheet.getCell('C3').value = 'Jméno dítěte';
    worksheet.getCell('D3').value = 'Poznámka';

    worksheet.getRow(3).eachCell(cell => {
        cell.font = { bold: true };
        cell.alignment = { horizontal: 'center', vertical: 'middle' };
        cell.border = { top: { style: 'thick' }, left: { style: 'thick' }, right: { style: 'thick' }, bottom: { style: 'thick' }};
    });

    let rowIndex = 4;
    for (let time in data) {
        const reservations = data[time].reservations || [];

        if (reservations.length > 0) {
            let startRow = rowIndex;

            reservations.forEach((reservation, index) => {
                worksheet.addRow({
                    time: index === 0 ? time : '',
                    evc: reservation.evc || '',
                    name: reservation.name || '',
                    desc: reservation.desc || ''
                });

                worksheet.getCell(`A${rowIndex}`).border = worksheet.getCell(`B${rowIndex}`).border = worksheet.getCell(`C${rowIndex}`).border = worksheet.getCell(`D${rowIndex}`).border = {
                    top: { style: 'thin' },
                    left: { style: 'thin' },
                    bottom: { style: 'thin' },
                    right: { style: 'thin' }
                };
                worksheet.getCell(`A${startRow}`).alignment = { horizontal: 'center', vertical: 'middle' };
                rowIndex++;
            });
            if (reservations.length > 1) {
                worksheet.mergeCells(`A${startRow}:A${rowIndex - 1}`);
                worksheet.getCell(`A${startRow}`).alignment = { horizontal: 'center', vertical: 'middle' };
            }
            setDynamicBorder(worksheet, startRow, rowIndex - 1, 1, 4);
        }
    }

    res.setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    res.setHeader('Content-Disposition', 'attachment; filename=rezervace.xlsx');

    function setDynamicBorder(worksheet, startRow, endRow, startCol, endCol) {
        for (let rowNumber = startRow; rowNumber <= endRow; rowNumber++) {
            let row = worksheet.getRow(rowNumber);
            row.eachCell({includeEmpty: true},(cell, colNumber) => {
                if (colNumber >= startCol && colNumber <= endCol) {
                    let borderStyle = { style: 'thin' };

                    cell.border = {
                        top: rowNumber === startRow ? { style: 'thick' } : borderStyle,
                        left: colNumber === startCol ? { style: 'thick' } : borderStyle,
                        bottom: rowNumber === endRow ? { style: 'thick' } : borderStyle,
                        right: colNumber === endCol ? { style: 'thick' } : borderStyle
                    };
                }
            });
        }
    }

    await workbook.xlsx.write(res);
    res.end();
});

app.listen(port, () => {
    console.log(`Server je v provozu.`);
});
