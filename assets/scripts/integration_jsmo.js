;{
    // Define the jsmo in IIFE so we can reference object in our new function methods
    // The module_name will be hardcoded to the EM main Class Name;
    const module_name   = "OnCoreIntegration";
    const module        = ExternalModules.Stanford[module_name];

    // Extend the official JSMO with new methods
    Object.assign(module, {
        InitFunction: function () {
            // console.log("JSMO Init Function");
            const field_map_url             = module.config["field_map_url"];
            const has_oncore_link           = module.config["has_oncore_integration"];
            const oncore_integrations       = module.config["oncore_integrations"];
            const oncore_protocols          = module.config["oncore_protocols"];
            const has_field_mappings        = module.config["has_field_mappings"];
            const last_adjudication         = module.config["last_adjudication"];
            const matching_library          = module.config["matching_library"];

            //THIS IS MATCHING ROWS STUFFED IN ENTITY TABLE
            const multiple_protocols        = oncore_protocols.length > 1 ? true : false;
            const integrated_protocolIds    = Object.keys(oncore_integrations);
            const has_oncore_integration    = integrated_protocolIds.length ? true : false;  //has entitry record, but not necessarily linked

            // console.log("init", has_oncore_link, has_oncore_integration, oncore_integrations, oncore_protocols);

            var make_oncore_module = function () {
                if ($("#setupChklist-modify_project").length) {
                    //BORROW HTML FROM EXISTING UI ON SET UP PAGE
                    var new_section = $("#setupChklist-modules").clone();
                    new_section.attr("id", "integrateOnCore-modules");
                    new_section.find(".chklist_comp").remove();
                    new_section.find(".chklisthdr span").text("OnCore Project Integration");
                    new_section.find(".chklisttext").empty();

                    if (new_section.find("img#img-modules").length) {
                        new_section.find("img#img-modules").attr("id", "img-oncore");
                        var img_src = new_section.find("img#img-oncore").attr("src");
                        var src_tmp = img_src.split("/");
                        src_tmp.pop();
                        src_tmp.push("checkbox_gear.png");
                        src_tmp = src_tmp.join("/");
                        new_section.find("img#img-oncore").attr("src", src_tmp);
                    }

                    $("#setupChklist-modify_project").after(new_section);
                    var content_bdy = new_section.find(".chklisttext");
                    var lead = $("<span>");
                    content_bdy.append(lead);

                    //IF ONCORE HAS BEEN INTEGATED WITH THIS PROJECT, THEN DISPLAY SUMMARY OF LAST ADJUDICATION
                    if (has_field_mappings) {
                        var lead_class = "oncore_results";
                        var lead_text = "Results summary from last adjudication : ";
                        lead_text += "<ul class='summary_oncore_adjudication'>";
                        lead_text += "<li>Total Subjects : " + last_adjudication["total_count"] + "</li>";
                        lead_text += "<li>Full Match : " + last_adjudication["full_match_count"] + "</li>";
                        lead_text += "<li>Partial Match : " + last_adjudication["partial_match_count"] + "</li>";
                        lead_text += "<li>Oncore Only : " + last_adjudication["oncore_only_count"] + "</li>";
                        lead_text += "<li>REDCap Only : " + last_adjudication["redcap_only_count"] + "</li>";
                        lead_text += "</ul>";
                    } else {
                        var lead_class = "oncore_mapping";
                        var lead_text = "Please <a href='" + field_map_url + "'>Click Here</a> to map OnCore fields to this project.";
                    }

                    lead.addClass(lead_class);
                    lead.html(lead_text);
                }
            };

            var make_check_approve_integration = function(entity_record_id, integrate){
                var check_label         = $("<label>").addClass("confirm_link").text(" Confirm Link to Protocol");
                var check_ui            = $("<input type='checkbox'/>").addClass("confirm_check").val(1);
                check_label.prepend(check_ui);
                $(".enable_oncore").append(check_label);

                check_ui.on("click", function () {
                    if ($(this).is(":checked")) {
                        $(this).attr("disabled", "disabled");

                        var data = {
                            "entity_record_id"  : entity_record_id,
                            "integrate"         : integrate
                        }

                        console.log("approve integration", data)
                        module.callAjax("approveIntegrateOncore", data, function (responseText) {
                            if (!responseText.success) {
                                var be_status   = "";
                                var be_lead     = "";
                                if (responseText.hasOwnProperty("result")) {
                                    var result  = decode_object(responseText.result);
                                    be_status   = result.hasOwnProperty("status") ? result.status.toUpperCase() + ". " : "";
                                    be_lead     = result.hasOwnProperty("message") ? result.message + "\r\n" : "";
                                } else {
                                    be_status   = "Unspecified Error";
                                }
                                $(".getadjudication").prop("disabled", false);

                                var headline    = be_status;
                                var lead        = be_lead + "Please try again";
                                var notif       = new notifModal(lead, headline);
                                notif.show();

                                //remove checkbox UI
                                $(".confirm_link").remove();
                            } else {
                                window.location.reload();
                            }
                        }, function (e) {
                            // console.log("failed!", e);
                            var headline    = "Unspecified Error";
                            var lead        = "Please try again";
                            var notif       = new notifModal(lead, headline);
                            notif.show();
                        });
                    }
                });
                check_ui.trigger("click");
            };

            var integrate_protocol = function(protocolId, irb, _cb){
                var data = {
                    "irb": irb,
                    "oncore_protocol_id": protocolId
                };

                //FIRST AJAX to link to ONCORE PROJECT WHEN CONFIRM CHECKBOX IS CHECKED
                module.callAjax("integrateOnCore", data, function (responseText) {
                    if (!responseText.success) {
                        var be_status   = "";
                        var be_lead     = "";
                        if (responseText.hasOwnProperty("result")) {
                            var result  = decode_object(responseText.result);
                            be_status   = result.hasOwnProperty("status") ? result.status.toUpperCase() + ". " : "";
                            be_lead     = result.hasOwnProperty("message") ? result.message + "\r\n" : "";
                        } else {
                            be_status   = "Unspecified Error";
                        }
                        $(".getadjudication").prop("disabled", false);

                        var headline    = be_status;
                        var lead        = be_lead + "Please try again";
                        var notif       = new notifModal(lead, headline);
                        notif.show();

                        //remove checkbox UI
                        $(".confirm_link").remove();
                    } else {
                        if (_cb instanceof Function) {
                            _cb(responseText.result);
                        }
                    }
                }, function (e) {
                    // console.log("failed!", e);
                    $(".oncore_link_working").removeClass("oncore_link_working");
                    var headline    = "Unspecified Error";
                    var lead        = "Please try again";
                    var notif       = new notifModal(lead, headline);
                    notif.show();
                });
            };

            //  this over document.ready because we need this last!
            $(window).on('load', function () {
                //ADD ADJUDICATION STAT METRICS IF IS LINKED TO AN ONCORE PROJECT
                if (has_oncore_link) {
                    //BORROW UI FROM OTHER ELEMENT TO ADD A NEW MODULE TO PROJECT SETUP
                    make_oncore_module();
                }

                //INJECT LINE TO MAIN PROJECT SETTINGS MODULE IF THERE IS POSSIBLE ONCORE INTEGRATION
                if (oncore_protocols.length) {
                    if ($("#setupChklist-modify_project button:contains('Modify project title, purpose, etc.')").length) {
                        //ONLY WAY IT SHOWS MULTIPLE DROPD DOWN IS IF THERE ARE MULTIPLE ONCORE PROTOCOLS , HAS NOT YET HAD AN APPROVED LINK , AND NOT ENTITY RECORDS (PREVIOUS PROTOCOL LINKS)
                        if(!has_oncore_link && multiple_protocols){
                            var btn_text            = "Link Project&nbsp;";
                            var integrated_class    = "not_integrated";
                            var dropdown            = `<div class="dropdown">
                                                          <a class="btn-defaultrc btn-xs fs11 dropdown-toggle integrate_oncore" href="#" role="button" id="dropdownMenuLink" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><i class="fa fa-cog fa-spin"></i>`+btn_text+`</a>
                                                          <div class="dropdown-menu" aria-labelledby="dropdownMenuLink"></div>
                                                        </div>`;
                            var button              = $(dropdown);

                            for (var i in oncore_protocols) {
                                var item                = oncore_protocols[i];
                                var protocolId          = item["protocolId"];
                                var protocol            = item["protocol"];

                                var protocol_status     = protocol["protocolStatus"];
                                var protocol_title      = protocol["shortTitle"];
                                var oncore_library      = protocol["library"];
                                var irb                 = item["irbNo"];

                                var list_item = $("<a>").attr("href", "#").data("irb", irb).data("protocolId",protocolId).data("protocol_title",protocol_title).data("oncore_library",oncore_library).data("protocol_status",protocol_status).addClass("dropdown-item").text(protocol_title + " ("+oncore_library+") ["+protocol_status+"]");
                                list_item.on("click", function(){
                                    $(".integrate_oncore").addClass("oncore_link_working");
                                    var protocolId          = $(this).data("protocolId");
                                    let oc_library          = $(this).data(oncore_library) ? "("+$(this).data(oncore_library)+")" : "";
                                    var new_text            = "Link OnCore Protocol IRB #"+irb+" : <b>"+$(this).data("protocol_title")+"</b> "+oc_library+" ["+$(this).data("protocol_status")+"]";
                                    $(".enable_oncore").html(new_text);

                                    integrate_protocol(protocolId, irb, function(rt){
                                        var result  = decode_object(rt);
                                        make_check_approve_integration(result.id, 1);
                                    });
                                });

                                button.find(".dropdown-menu").append(list_item);
                            }
                            var line_text           = "Multiple matching OnCore Protocols for IRB #" + irb + "."  ;
                        }else{
                            if(has_oncore_integration || !matching_library) {
                                if(integrated_protocolIds.length == 1){
                                    var protocolId          = integrated_protocolIds.pop();
                                    var integrated_protocol = oncore_integrations[protocolId];

                                    var entity_record_id    = integrated_protocol.id;
                                    var integration_status  = integrated_protocol.status;
                                }

                                for (var i in oncore_protocols) {
                                    var pr = oncore_protocols[i];

                                    if (pr["protocolId"] == protocolId || !matching_library) {
                                        // console.log(protocolId, entity_record_id, integration_status, pr["protocol"]);
                                        var integration     = oncore_integrations[protocolId];
                                        var protocol        = pr["protocol"];

                                        var protocol_status = protocol["protocolStatus"];
                                        var protocol_title  = protocol["shortTitle"];
                                        var library         = protocol["library"];
                                        var irb             = pr["irbNo"];

                                        var btn_text            = has_oncore_link ? "Unlink Project&nbsp;" : "Link Project&nbsp;";
                                        var integrated_class    = has_oncore_link ? "integrated" : "not_integrated";

                                        var button              = $("<a>").data("irb", irb).data("protocolId", protocolId).data("entity_record_id",entity_record_id).addClass("integrate_oncore").addClass("btn btn-defaultrc btn-xs fs11").html(btn_text);
                                        button.prepend($("<i class=\"fa fa-cog fa-spin\"/>"));
                                        var asstrick            = "";
                                        if(!matching_library){
                                            button.data("no_matching_library", library);
                                            asstrick = "<i class='no_matching_library'>*</i>";
                                        }
                                        var line_text           = "OnCore Protocol : <b>" + protocol_title + " ("+library+asstrick+")</b> [<i>" + protocol_status.toLowerCase() + "</i>]";

                                        line_text = has_oncore_link ? line_text : line_text;
                                        break;
                                    }
                                }
                            }
                        }

                        var integrate_text      = $("<span>").addClass("enable_oncore").html(line_text);
                        var inject_line         = $("<div>").addClass(integrated_class).addClass("injected_line").attr("style", "padding:2px 0;font-size:13px;");

                        inject_line.append($("<div class='button_div'>").append(button));
                        inject_line.append(integrate_text);

                        $("#setupChklist-modify_project button:contains('Modify project title, purpose, etc.')").before(inject_line);
                    }
                }

                //LINK/UNLINK OnCore Project UI
                $(".integrate_oncore").on("click", function (e) {
                    e.preventDefault();

                    var _par                = $(this).closest(".injected_line");
                    var need_to_integrate   = _par.hasClass("not_integrated") ? 1 : 0;
                    var entity_record_id    = $(this).data("entity_record_id");
                    var no_matching_library = $(this).data("no_matching_library");

                    if(multiple_protocols && need_to_integrate){
                        return;
                    }

                    $(this).addClass("oncore_link_working");
                    if(!entity_record_id && no_matching_library){
                        $(this).removeClass("oncore_link_working");
                        var be_status   = "";
                        var be_lead     = "No matching library found for <b class='no_matching_library'>" + no_matching_library + "</b>.";

                        var headline    = be_status;
                        var lead        = be_lead + " <br/>Please contact REDCap admin.";
                        var notif       = new notifModal(lead, headline);
                        notif.show();
                    }

                    if(!$(".confirm_link").length && entity_record_id ){
                        // console.log("if multiple (with no existing integrations), just dropdown, if single, then jump straight to showing checkbox", entity_record_id, need_to_integrate);
                        make_check_approve_integration(entity_record_id, need_to_integrate);
                    }

                    return;
                });
            });
        },

        callAjax: function (action, payload, success_cb, err_cb) {
            module.ajax(action, payload).then(function (response) {
                // Process response
                console.log(action + " Ajax Result: ", response);
                if (success_cb instanceof Function) {
                    success_cb(response);
                }
            }).catch(function (err) {
                // Handle error
                console.log(action + " Ajax Error: ", err);
                if (err_cb instanceof Function) {
                    err_cb(err);
                }
            });
        },

        Log: function(subject, msg_o){
            module.log(subject, msg_o).then(function(logId) {
                console.log("message logged", logId, subject, msg_o);
            }).catch(function(err) {
                console.log("error logging message", err);
            });
        }
    });
}
