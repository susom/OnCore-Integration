class adjudicationModal{
    //DEFAULT VALUES
    id              = "blockingOverlay";
    completedItems  = 0;
    totalItems      = 0;
    lead            = "";
    headerText      = "";
    bin             = "";
    modal           = null;
    /*
     must include batch_modal.css
     expected inputs structure :  [{value:1, mrn:12345}, {value:2, mrn:23456}]
     [value] and [mrn] required

     can change headerText and colHeaders (as long as it remains at 4 values) with set function
    */

    constructor(bin){
        this.bin = bin;

        switch(bin){
            case "oncore":
                var hdrtxt  = "Pull OnCore subjects into REDCap";
                var leadtxt = "The following Subjects were found in the OnCore Protocol but not in the REDCap project.";
                break;
            case "redcap":
                var hdrtxt  = "Push REDCap records into OnCore";
                var leadtxt = "The following REDCap records have an MRN not found in the OnCore Protocol.";
                break;
            case "partial":
                var hdrtxt  = "Adjudicate Partial Matches (Diff)";
                var leadtxt = "There was an MRN match between REDCap and OnCore, but the data is mis-matched. Choose to accept OnCore data (as the source of truth).";
                break;
        }

        this.headerText = hdrtxt;
        this.lead       = leadtxt;
    }

    buildHTML(){
        var _this   = this;


        this.hide();

        let modalID = _this.id;
        var opaque  = $("<div>").attr("id",modalID);

        var modal   = $(modal_tpl);
        var close   = modal.find("button.close");
        close.on("click",function(){
            _this.hide();
        });

        modal.find(".pushHDR span").text(_this.headerText);
        modal.find(".lead").text(_this.lead); //lead text

        opaque.appendTo("body");
        modal.appendTo(opaque);

        this.modal = modal;
    }

    show(){
        this.buildHTML();
    }

    hide(){
        let modalID = "#" + this.id;
        $(modalID).remove();
        return;
    }

    prepCount(){
        this.modal.find(".batch_counter b").text(this.completedItems); //current
        this.modal.find(".batch_counter i").text(this.totalItems); //total
    }

    showContinue(){
        this.modal.find("#ajaxq_buttons .pause").addClass("paused").html("Continue Sync");
    }

    showProgressUI(){
        this.prepCount();
        this.growProgressBar();
        $(".modal_progress").slideDown("medium");
    }

    hideProgressUI(){
        $(".modal_progress").slideUp("fast");
    }

    growProgressBar(){
        var perc        = this.completedItems / this.totalItems;
        var pbar_width  = Math.round(perc * 100)+ "%";
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
            this.enableSubmit();

            $(".batch_counter em").text("Completed!");
        }else{
            this.disableSubmit();

            $(".batch_counter em").text("");
        }
    }

    disableSubmit(){
        this.modal.find(".footer_action button").attr("disabled","disabled");
    }

    enableSubmit(){
        this.modal.find(".footer_action button[disabled='disabled']").attr("disabled",false);
    }



    setRowStatus(id, status, note){
        var status_txt = "failed";
        if (status) {
            status_txt = "ok";
        } else {
            // log only errors in error box.
            this.logMessage(note);
        }

        this.setRowNote(id, status_txt, note)
        this.incFinished();
        $(".pushDATA td[data-status_rowid='" + id + "']").addClass(status_txt).html(status_txt);
    }

    setRowNote(id, status, note){
        //this.logMessage(note);
        $(".pushDATA td[data-note_rowid='" + id + "']").addClass(status).html(note.replace(/(?:\r\n|\r|\n)/g, '<br>'));
    }

    logMessage(msg) {
        var existing_msg    = $("#modal_msg").val();
        var new_msg         = existing_msg.length ? existing_msg + "\r\n" + msg : msg;
        var cleanText       = new_msg.replace(/<\/?[^>]+(>|$)/g, " ");

        $("#modal_msg").val(cleanText);
    }

    randomIntFromInterval(min, max) { // min and max included
        return Math.floor(Math.random() * (max - min + 1) + min)
    }

    get totalItems(){
        return this.totalItems;
    }

    set totalItems(item_n){
        return this.totalItems = item_n;
    }

    get completedItem(){
        return this.completedItems;
    }

    set completedItem(item_n){
        return this.completedItems = item_n;
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
}

var modal_tpl = `<div id="pushModal">
                    <h2 class="pushHDR">
                        <span></span><button type="button" class="close" data-dismiss="modal">×</button>
                    </h2>

                    <div class="pushBDY">
                        <p class="lead pull-left"></p><span class="show_all pull-right"></span>

                        <div class="modal_progress">
                            <div class="batch_counter"><em></em> <b>0</b>/<i></i></div>
                            <div id="pbar_box"><span id="pbar"></span></div>
                            <div id="ajaxq_buttons"><button class="pause btn btn-small btn-warning">Pause</button> <button class="cancel btn btn-small btn-danger">Cancel Sync</button></div>
                            <label>Errors:</label>
                            <textarea id="modal_msg" disabled="disabled"></textarea>
                        </div>
                    </div>

                    <div class="pushDATA"></div>

                    <div class="pushFTR">
                        <div class="footer_action"></div>
                    </div>
                </div>`;
