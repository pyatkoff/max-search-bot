(function () {
    'use strict';
    var csrf = document.body.dataset.csrf;
    if (!csrf) return;
    var busy = false;
    var accountBox = document.getElementById('accounts');
    var feedback = document.getElementById('feedback');

    function message(text, error) {
        feedback.textContent = text || '';
        feedback.className = error ? 'feedback error' : 'feedback';
        feedback.hidden = !text;
    }
    function node(tag, className, text) {
        var element = document.createElement(tag);
        if (className) element.className = className;
        if (text !== undefined) element.textContent = text;
        return element;
    }
    function render(data) {
        var bot = data.bot;
        var ready = bot.configured && bot.webhook_ready && bot.business_mode_enabled;
        var state = document.getElementById('botState');
        state.textContent = ready ? 'Готов к подключению' : (bot.configured ? 'Нужна настройка' : 'Нужен токен');
        state.className = 'badge' + (ready ? ' ready' : ' pending');
        document.getElementById('tokenForm').hidden = !!bot.configured;
        document.getElementById('botDetails').hidden = !bot.configured;
        document.getElementById('secretaryHelp').hidden = !bot.configured || !!bot.business_mode_enabled;
        document.getElementById('botExplanation').textContent = bot.needs_readback
            ? 'Проверяем результат подключения. Сообщите администратору, если состояние не изменится после проверки.'
            : (ready ? 'Можно добавить бота в рабочий Telegram-аккаунт.' : 'Завершите настройку бота, затем проверьте подключение.');
        accountBox.replaceChildren();
        if (!data.accounts.length) accountBox.appendChild(node('p', 'empty', 'Подключённых аккаунтов пока нет. После добавления бота в Telegram нажмите «Обновить».'));
        data.accounts.forEach(function (account) {
            var card = node('article', 'account');
            var identity = node('div', 'accountIdentity');
            var details = node('div');
            details.appendChild(node('p', 'accountName', account.display_name || 'Рабочий аккаунт'));
            if (account.username) details.appendChild(node('p', 'accountMeta', '@' + account.username));
            identity.appendChild(details);
            var enabled = account.connected && account.approved;
            identity.appendChild(node('span', 'badge' + (enabled ? ' ready' : ' pending'), !account.connected ? 'Отключён' : (enabled ? 'Сбор включён' : 'Ожидает включения')));
            card.appendChild(identity);
            if (account.connected) {
                var controls = node('div', 'accountActions');
                var select = node('select');
                select.setAttribute('aria-label', 'Привязка для ' + (account.display_name || account.username || 'аккаунта'));
                var placeholder = node('option', '', 'Общий аккаунт или менеджер'); placeholder.value = ''; select.appendChild(placeholder);
                var shared = node('option', '', 'Общий аккаунт'); shared.value = 'shared'; select.appendChild(shared);
                data.managers.forEach(function (manager) {
                    var option = node('option', '', manager.display_name); option.value = String(manager.id); select.appendChild(option);
                });
                select.value = account.manager_id || (account.approved ? 'shared' : '');
                controls.appendChild(select);
                var approve = node('button', 'button', account.approved ? 'Сохранить' : 'Включить сбор'); approve.type = 'button';
                approve.addEventListener('click', function () {
                    if (!select.value) { message('Выберите общий аккаунт или конкретного менеджера.', true); select.focus(); return; }
                    var choice = {action: 'approve', account_id: account.account_id, account_type: select.value === 'shared' ? 'shared' : 'manager'};
                    if (select.value !== 'shared') choice.manager_id = select.value;
                    perform(choice, 'Сбор для аккаунта включён.');
                });
                controls.appendChild(approve); card.appendChild(controls);
                card.appendChild(node('p', 'accountHint', 'Для общего аккаунта подписи менеджеров сохраняются в тексте переписки.'));
                if (account.approved) {
                    var disable = node('button', 'textButton', 'Остановить сбор'); disable.type = 'button';
                    disable.addEventListener('click', function () { perform({action: 'disable', account_id: account.account_id}, 'Сбор для аккаунта остановлен.'); });
                    card.appendChild(disable);
                } else card.appendChild(node('p', 'accountHint', 'До включения сообщения этого аккаунта не сохраняются.'));
            }
            accountBox.appendChild(card);
        });
    }
    async function request(body) {
        var data = await window.ManagerHttpClient.request(body ? body.action : 'status', body || {}, csrf, 'telegram-archive-api.php');
        if (!data.ok) {
            var status = data.error === 'unauthorized' ? 401 : (['forbidden','csrf'].includes(data.error) ? 403 : 0);
            var failure = new Error(status === 401 ? 'Войдите в кабинет администратора и откройте настройку снова.' :
                (status === 403 ? 'Для настройки нужен доступ администратора проекта.' : (data.message || 'Не удалось обновить настройку. Попробуйте позже.')));
            failure.status = status; failure.userMessage = true; throw failure;
        }
        return data;
    }
    async function perform(body, success) {
        if (busy) return;
        busy = true;
        document.querySelectorAll('button').forEach(function (button) { button.disabled = true; });
        try { render(await request(body)); message(success || ''); }
        catch (error) {
            if (error.status === 401 || error.status === 403) {
                accountBox.replaceChildren(node('p', 'empty', 'Откройте настройку под администратором проекта.'));
                document.getElementById('tokenForm').hidden = true;
                document.getElementById('botDetails').hidden = true;
                document.getElementById('secretaryHelp').hidden = true;
                document.getElementById('botState').textContent = 'Требуется вход';
            }
            message(error.userMessage ? error.message : 'Не удалось соединиться с сервером. Попробуйте позже.', true);
        }
        finally {
            document.getElementById('botToken').value = '';
            busy = false;
            document.querySelectorAll('button').forEach(function (button) { button.disabled = false; });
        }
    }
    document.getElementById('tokenForm').addEventListener('submit', function (event) {
        event.preventDefault();
        var token = document.getElementById('botToken').value.trim();
        if (token) perform({action: 'configure', token: token}, 'Бот настроен. Проверьте следующие шаги.');
    });
    document.getElementById('inspectBot').addEventListener('click', function () { perform({action: 'inspect'}, 'Состояние бота обновлено.'); });
    document.getElementById('changeToken').addEventListener('click', function () {
        document.getElementById('tokenForm').hidden = false; document.getElementById('botToken').focus();
    });
    document.getElementById('refreshAccounts').addEventListener('click', function () { perform(null); });
    perform(null);
})();
