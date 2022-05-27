class notifModal{
    //DEFAULT VALUES
    id                  = "blockingOverlay";
    default_lead        = "Something failed in the last operation.";
    default_headline    = "Error";

    constructor(lead, headline,  id){
        this._lead      = lead ?? this.default_lead;
        this._headline  = headline ?? this.default_headline;
    }

    buildHTML(){
        var _this   = this;
        let modalID = _this.id;
        var opaque  = $("<div>").attr("id",modalID);

        var modal   = $(notif_modal_tpl);

        var bigicon = $('<i class="fas fa-skull-crossbones"></i>');

        modal.find(".notif_hdr").html(bigicon);
        modal.find(".notif_bdy .headline").text(this._headline);
        modal.find(".notif_bdy .lead").text(this._lead);

        modal.find(".notif_ftr button").on("click", function(){
            _this.hide();
        });

        opaque.appendTo("body");
        modal.appendTo(opaque);
    }


    show(){
        this.buildHTML();
    }

    hide(){
        let modalID = "#" + this.id;
        $(modalID).remove();
        return;
    }
}


var notif_modal_tpl = `<div id="notifModal" class="danger">
                            <div class="notif_hdr"></div>
                            <div class="notif_bdy">
                                <h3 class="headline"></h3>
                                <div class="lead"></div>
                            </div>
                            <div class="notif_ftr"><button>Close</button></div>
                       </div>`;
