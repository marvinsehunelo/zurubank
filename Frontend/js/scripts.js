// Form Validation
document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', function(e){
        const amountInput = form.querySelector('input[name="amount"]');
        if(amountInput && amountInput.value <= 0){
            e.preventDefault();
            alert('Amount must be greater than 0');
        }
        const emailInput = form.querySelector('input[name="email"]');
        if(emailInput && !emailInput.value.includes('@')){
            e.preventDefault();
            alert('Please enter a valid email address');
        }
    });
});

// Simple dynamic alerts
function showAlert(message, type='success') {
    const div = document.createElement('div');
    div.className = 'alert alert-' + type;
    div.innerText = message;
    document.body.prepend(div);
    setTimeout(() => div.remove(), 4000);
}
