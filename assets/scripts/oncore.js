function decode_object(obj) {
    for (key in obj) {
        if (typeof obj[key] === Object) {
            return decode_object(obj[key])
        } else {
            obj[key] = decode_string(obj[key])
        }
    }
    return obj
}

function decode_string(input) {
    var txt = document.createElement("textarea");
    txt.innerHTML = input;
    return txt.value;
}
