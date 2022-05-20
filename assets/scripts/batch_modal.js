class batchModal{
    //DEFAULT VALUES
    id              = "blockingOverlay";
    completedItems  = 0;
    lead            = "Please leave this modal open while the data is uploaded.";
    headerText = "Pushing REDCap to OnCore";
    colHeaders = ["REDCap ID", "OnCore Subject Demographics ID", "MRN", "Status", "Note"];

    /*
     must include batch_modal.css
     expected inputs structure :  [{value:1, mrn:12345}, {value:2, mrn:23456}]
     [value] and [mrn] required

     can change headerText and colHeaders (as long as it remains at 4 values) with set function
    */

    constructor(inputs, id){
        this._inputs        = inputs;
        this.totalItems     = Object.keys(inputs).length;
    }

    buildHTML(){
        var _this   = this;
        let modalID = _this.id;
        var opaque  = $("<div>").attr("id",modalID);

        var modal   = $("<div>").attr("id", "pushModal");
        var hdr     = $("<h2>").addClass("pushHDR").text(_this.headerText);
        var close   = $('<button type="button" class="close" data-dismiss="modal">×</button>');
        hdr.append(close);

        var bdy     = $("<div>").addClass("pushBDY");
        var lead    = $('<p class="lead">'+_this.lead+'</p>');
        bdy.append(lead);

        var counter = $('<div>').addClass("batch_counter");
        counter.append($("<em></em> <b>"+_this.completedItems+"</b>/<i>"+_this.totalItems+"</i>"));
        bdy.append(counter);

        var pbar    = $('<div id="pbar_box"><span id="pbar"></span></div>');
        bdy.append(pbar);

        var data    = $("<div>").addClass("pushDATA");
        var tbl     = $("<table>").addClass("pushTBL");
        data.append(tbl);

        var row = $("<tr><th>" + _this.colHeaders[0] + "</th><th>" + _this.colHeaders[1] + "</th><th>" + _this.colHeaders[2] + "</th><th>" + _this.colHeaders[3] + "</th><th>" + _this.colHeaders[4] + "</th></tr>");
        tbl.append(row);

        for(var i in _this._inputs){
            var inp = _this._inputs[i];
            var row = $("<tr><td>" + inp["redcap"] + "</td><td>" + inp["oncore"] + "</td><td>" + inp["mrn"] + "</td><td data-status_rowid='" + inp["value"] + "'></td><td data-note_rowid='" + inp["value"] + "'></td></tr>");
            tbl.append(row);
        }

        var ftr     = $("<div>").addClass("pushFTR");
        ftr.append($("<label>").text("Errors:"));
        ftr.append($("<textarea>").attr("id","modal_msg").attr("disabled","disabled"));

        modal.append(hdr);
        modal.append(bdy);
        modal.append(data);
        modal.append(ftr);

        close.on("click",function(){
            _this.hide();
        });

        opaque.appendTo("body");
        modal.appendTo(opaque);
    }

    showtotalItems() {
        return this.totalItems;
    }

    show(){
        this.buildHTML();
    }

    hide(){
        let modalID = "#" + this.id;
        $(modalID).remove();
        return;
    }

    growProgressBar(){
        var perc = this.completedItems / this.totalItems;
        var pbar_width = Math.round(perc * 100)+ "%";
        $("#pbar").width(pbar_width);

        this.completeMsg();
    }

    incFinished() {
        this.completedItems++;
        this.growProgressBar();
        $(".batch_counter b").text(this.completedItems);
    }

    completeMsg(){
        if(this.totalItems == this.completedItems) {
            $(".batch_counter em").text("Completed!");
        }
    }

    setRowStatus(id, status, note){
        var status_txt = "failed";
        if(status){
            status_txt = "ok";
        }else{
            this.setRowNote(id, note)
        }
        this.incFinished();
        $(".pushTBL td[data-status_rowid='" + id + "']").html(status_txt);
    }

    setRowNote(id, note){
        this.logMessage(note);
        $(".pushTBL td[data-note_rowid='" + id + "']").html(note);
    }

    logMessage(msg) {
        var existing_msg = $("#modal_msg").val();
        var new_msg = existing_msg.length ? existing_msg + "\r\n" + msg : msg;
        var cleanText = new_msg.replace(/<\/?[^>]+(>|$)/g, " ");

        $("#modal_msg").val(cleanText);
    }

    randomIntFromInterval(min, max) { // min and max included
        return Math.floor(Math.random() * (max - min + 1) + min)
    }

    get lead(){
        return this.lead;
    }

    set lead(txt){
        return this.lead = txt;
    }

    get headerText(){
        return this.headerText;
    }

    set headerText(_txt){
        return this.headerText = _txt;
    }

    get colHeaders(){
        return this.colHeaders;
    }

    set colHeaders(_arr){
        return this.colHeaders = _arr;
    }
}
