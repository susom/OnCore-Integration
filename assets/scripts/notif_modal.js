class notifModal{
    //DEFAULT VALUES
    id                  = "blockingOverlay";
    default_lead        = "Something failed in the last operation.";
    default_headline    = "Error";

    constructor(lead, headline,  id){
        this._lead      = (lead ?? this.default_lead) + ($("#support-url").val() != '' ? '<br><a target="_blank" href="' + $("#support-url").val() + '">For more information check Oncore Support Page</a>' : '');
        this._headline  = headline ?? this.default_headline;
    }

    buildHTML(){
        var _this   = this;

        this.hide();

        let modalID = _this.id;
        var opaque  = $("<div>").attr("id",modalID);

        var modal   = $(notif_modal_tpl);

        var bigicon = $('<i class="fas fa-exclamation-triangle"></i>');

        modal.find(".notif_hdr").html(bigicon);
        modal.find(".notif_bdy .headline").html(this._headline);
        modal.find(".notif_bdy .lead").html(this._lead);

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
