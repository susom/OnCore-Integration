function decode_object(obj) {
    // parse text to json object
    var parsedObj = obj;
    if (typeof obj === 'string') {
        var temp = obj.replace(/&quot;/g, '"')
        parsedObj = JSON.parse(temp);
    }

    for (key in parsedObj) {
        if (typeof parsedObj[key] === 'object') {
            parsedObj[key] = decode_object(parsedObj[key])
        } else {
            parsedObj[key] = decode_string(parsedObj[key])
        }
    }
    return parsedObj
}

function decode_string(input) {
    var txt = document.createElement("textarea");
    txt.innerHTML = input;
    return txt.value;
}
