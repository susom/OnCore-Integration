function decode_object(obj) {
    try {
        // parse text to json object
        var parsedObj = obj;
        if (typeof obj === 'string') {
            var temp = obj.replace(/&quot;/g, '"').replace(/[\n\r\t\s]+/g, ' ')
            parsedObj = JSON.parse(temp);
        }

        for (key in parsedObj) {
            if (typeof parsedObj[key] === 'object') {
                parsedObj[key] = decode_object(parsedObj[key])
            } else {
                // ignore boolean because changing them to string will cause error.
                if (typeof parsedObj[key] != 'boolean') {
                    parsedObj[key] = decode_string(parsedObj[key])
                }
            }
        }
        return parsedObj
    } catch (error) {
        console.error(error);
        alert(error)
        // expected output: ReferenceError: nonExistentFunction is not defined
        // Note - error messages will vary depending on browser
    }

}

function decode_string(input) {
    var txt = document.createElement("textarea");
    txt.innerHTML = input;
    return txt.value;
}
