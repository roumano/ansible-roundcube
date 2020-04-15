  rcmail.addEventListener('init', function()
  {
	var text = '<tr class="from-group row">';
	text += '<td class=title" style="display: none;">';
	text += '<label for="radius_code">OTP</label>';
	text += '</td>';
        text += '<td class="input input-group input-group-lg">';
        text += '<span class="input-group-prepend">';
        text += '<i class="input-group-text icon pass"></i>';
        text += '</span>';
        text += '<input name="_code" class="form-control" required="" id="radius_code" autocapitalize="off" autocomplete="off" type="text" placeholder="OTP Code">';
        text += '</td></tr>';
	div = document.querySelector('table tr:last-child');
   //alert(text);
   div.insertAdjacentHTML( 'afterend', text );
  });
