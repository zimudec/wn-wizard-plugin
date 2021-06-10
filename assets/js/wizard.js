$(window).on(
  "ajaxInvalidField",
  function (event, fieldElement, fieldName, errorMsg, isFirst) {
    // Show respective error messages for each field
    $(fieldElement)
      .addClass("is-invalid")
      .closest(".form-group")
      .find(".invalid-feedback")
      .addClass("invalid-feedback visible")
      .html(`${errorMsg.map((msg) => `<span>${msg}</span>`).join("")}`);
  }
);

$(document).on("ajaxPromise", "[data-request]", function () {
  $(this).find(".invalid-feedback").removeClass("visible");
  $(this).find(".form-control").removeClass("is-invalid");
});
