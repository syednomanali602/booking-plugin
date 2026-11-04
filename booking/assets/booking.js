(() => {
    const root = document.querySelector('.ccb-widget');
    if (!root || typeof CCB === 'undefined') return;

    const grid = root.querySelector('.ccb-calendar-grid');
    const monthLabel = root.querySelector('.ccb-month-label');
    const prev = root.querySelector('.ccb-prev');
    const next = root.querySelector('.ccb-next');
    const slotsBox = root.querySelector('.ccb-slots');
    const selectedLabel = root.querySelector('.ccb-selected-date');
    const continueBtn = root.querySelector('.ccb-continue');
    const formWrap = root.querySelector('.ccb-form-wrap');
    const form = root.querySelector('.ccb-form');
    const errorBox = root.querySelector('.ccb-form-error');
    const result = root.querySelector('.ccb-result');
    const timezoneLabel = root.querySelector('.ccb-timezone');

    const today = new Date();
    today.setHours(0, 0, 0, 0);
    let month = new Date(today.getFullYear(), today.getMonth(), 1);
    let selectedDate = null;
    let selectedTime = null;
    let selectedTimeLabel = '';

    timezoneLabel.textContent = '🌐 ' + CCB.timezone;

    const pad = n => String(n).padStart(2, '0');
    const dateKey = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

    function renderCalendar() {
        grid.querySelectorAll('.ccb-date, .ccb-empty').forEach(el => el.remove());
        monthLabel.textContent = month.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
        prev.disabled = month.getFullYear() === today.getFullYear() && month.getMonth() === today.getMonth();

        const first = new Date(month.getFullYear(), month.getMonth(), 1).getDay();
        const total = new Date(month.getFullYear(), month.getMonth() + 1, 0).getDate();

        for (let i = 0; i < first; i++) {
            const blank = document.createElement('span');
            blank.className = 'ccb-empty';
            grid.appendChild(blank);
        }

        for (let day = 1; day <= total; day++) {
            const date = new Date(month.getFullYear(), month.getMonth(), day);
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'ccb-date';
            btn.textContent = day;
            const weekend = date.getDay() === 0 || date.getDay() === 6;
            btn.disabled = date < today || weekend;
            if (selectedDate && dateKey(date) === dateKey(selectedDate)) btn.classList.add('is-active');
            btn.addEventListener('click', () => {
                selectedDate = date;
                selectedTime = null;
                continueBtn.disabled = true;
                renderCalendar();
                loadSlots();
            });
            grid.appendChild(btn);
        }
    }

    async function loadSlots() {
        slotsBox.innerHTML = '<p class="ccb-muted">Checking availability…</p>';
        selectedLabel.textContent = selectedDate.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' });
        try {
            const response = await fetch(`${CCB.availabilityUrl}?date=${encodeURIComponent(dateKey(selectedDate))}`);
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Unable to load availability.');
            slotsBox.innerHTML = '';
            if (!data.slots || !data.slots.length) {
                slotsBox.innerHTML = '<p class="ccb-muted">No available times on this date.</p>';
                return;
            }
            data.slots.forEach(slot => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'ccb-time-slot';
                btn.textContent = slot.label;
                btn.addEventListener('click', () => {
                    slotsBox.querySelectorAll('.ccb-time-slot').forEach(el => el.classList.remove('is-active'));
                    btn.classList.add('is-active');
                    selectedTime = slot.value;
                    selectedTimeLabel = slot.label;
                    continueBtn.disabled = false;
                });
                slotsBox.appendChild(btn);
            });
        } catch (error) {
            slotsBox.innerHTML = `<p class="ccb-error">${escapeHtml(error.message)}</p>`;
        }
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
    }

    prev.addEventListener('click', () => {
        month = new Date(month.getFullYear(), month.getMonth() - 1, 1);
        renderCalendar();
    });
    next.addEventListener('click', () => {
        month = new Date(month.getFullYear(), month.getMonth() + 1, 1);
        renderCalendar();
    });

    continueBtn.addEventListener('click', () => {
        if (!selectedDate || !selectedTime) return;
        root.querySelector('.ccb-booking-summary').textContent =
            `${selectedDate.toLocaleDateString()} at ${selectedTimeLabel} (${CCB.timezone}) · ${CCB.duration} minutes`;
        formWrap.hidden = false;
    });

    root.querySelector('.ccb-close').addEventListener('click', () => {
        formWrap.hidden = true;
    });

    form.addEventListener('submit', async event => {
        event.preventDefault();
        errorBox.textContent = '';
        const submit = root.querySelector('.ccb-submit');
        submit.disabled = true;
        submit.textContent = 'Booking…';

        const payload = {
            name: form.elements.name.value.trim(),
            email: form.elements.email.value.trim(),
            message: form.elements.message.value.trim(),
            date: dateKey(selectedDate),
            time: selectedTime
        };

        try {
            const response = await fetch(CCB.bookingUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': CCB.nonce
                },
                body: JSON.stringify(payload)
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Booking failed.');

            formWrap.hidden = true;
            result.hidden = false;
            result.innerHTML = `<div class="ccb-success-icon">✓</div><h3>Thank you, ${escapeHtml(payload.name)}!</h3><p>${escapeHtml(data.message)}</p>${data.meetUrl ? `<p><a href="${escapeHtml(data.meetUrl)}" target="_blank" rel="noopener">Join Google Meet</a></p>` : ''}<p>A calendar invitation will be sent to ${escapeHtml(payload.email)} if the event attendee invitation is accepted by Google.</p>`;
            continueBtn.disabled = true;
            slotsBox.innerHTML = '<p class="ccb-muted">Your selected time has been booked.</p>';
        } catch (error) {
            errorBox.textContent = error.message;
            submit.disabled = false;
            submit.textContent = 'Confirm Booking';
            if (error.message.toLowerCase().includes('slot')) loadSlots();
        }
    });

    renderCalendar();
})();
